-- Run after 004_admin.sql as migration user. All changes roll back.
BEGIN;
DO $$
DECLARE entry record; browser_role text;
BEGIN
    FOREACH browser_role IN ARRAY ARRAY['anon','authenticated'] LOOP
        FOR entry IN SELECT p.oid FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
            WHERE n.nspname='public' AND p.proname LIKE 'admin_%' LOOP
            IF has_function_privilege(browser_role,entry.oid,'EXECUTE') THEN
                RAISE EXCEPTION 'Browser role can execute admin function';
            END IF;
        END LOOP;
    END LOOP;
END;
$$;
SET LOCAL ROLE service_role;
DO $$
DECLARE
    actor uuid; normal uuid; target uuid; result jsonb;
    prefix text := 'admin-sql-' || gen_random_uuid();
BEGIN
    INSERT INTO public.users(email,password_hash,is_admin) VALUES(prefix||'-admin@example.invalid','admin-hash',true) RETURNING id INTO actor;
    INSERT INTO public.users(email,password_hash) VALUES(prefix||'-normal@example.invalid','normal-hash') RETURNING id INTO normal;
    IF (public.admin_list_users(normal,1,'',0)->>'error') <> 'forbidden' THEN RAISE EXCEPTION 'Normal user can list'; END IF;
    IF (public.admin_get_user(normal,1,actor)->>'error') <> 'forbidden' THEN RAISE EXCEPTION 'Normal user can read'; END IF;
    IF (public.admin_create_user(normal,1,prefix||'-bad@example.invalid','','hash',true,true)->>'error') <> 'forbidden' THEN RAISE EXCEPTION 'Normal user can create'; END IF;
    IF (public.admin_update_user(normal,1,actor,1,prefix||'-bad@example.invalid','',true,false,NULL)->>'error') <> 'forbidden' THEN RAISE EXCEPTION 'Normal user can edit'; END IF;
    IF (public.admin_delete_user(normal,1,actor,1)->>'error') <> 'forbidden' THEN RAISE EXCEPTION 'Normal user can delete'; END IF;
    IF (public.admin_list_users(actor,99,'',0)->>'error') <> 'forbidden' THEN RAISE EXCEPTION 'Stale actor accepted'; END IF;

    result := public.admin_create_user(actor,1,prefix||'-target@example.invalid','<script>Test</script>','target-hash',true,false);
    IF NOT (result->>'ok')::boolean OR result->'user' ? 'password_hash' THEN RAISE EXCEPTION 'Unsafe create response'; END IF;
    target := (result->'user'->>'id')::uuid;
    result := public.admin_list_users(actor,1,prefix,0);
    IF NOT (result->>'ok')::boolean OR (result->>'total')::integer <> 3 OR jsonb_array_length(result->'users') <> 3
        OR (result->'users'->0) ? 'password_hash' THEN RAISE EXCEPTION 'Unsafe or incorrect list'; END IF;
    IF (public.admin_get_user(actor,1,target)->'user') ? 'password_hash' THEN RAISE EXCEPTION 'Hash in read response'; END IF;
    IF (public.admin_create_user(actor,1,prefix||'-target@example.invalid','Overwrite','other-hash',true,true)->>'error') <> 'duplicate' THEN RAISE EXCEPTION 'Duplicate overwrote user'; END IF;
    IF (public.admin_update_user(actor,1,target,99,prefix||'-new@example.invalid','New',true,true,NULL)->>'error') <> 'conflict' THEN RAISE EXCEPTION 'Stale edit accepted'; END IF;

    UPDATE public.users SET email_confirmed_at=clock_timestamp() WHERE id=target;
    result := public.admin_update_user(actor,1,target,1,prefix||'-new@example.invalid','New',true,true,NULL);
    IF NOT (result->>'ok')::boolean OR (result->'user'->>'auth_version')::integer <> 2
        OR result->'user'->>'email_confirmed_at' IS NOT NULL THEN RAISE EXCEPTION 'Update/version/verification failed'; END IF;
    IF NOT EXISTS(SELECT 1 FROM public.users WHERE id=target AND password_hash='target-hash' AND is_admin) THEN RAISE EXCEPTION 'Blank reset or promotion failed'; END IF;
    IF NOT (public.admin_list_users(target,2,prefix,0)->>'ok')::boolean THEN RAISE EXCEPTION 'Promoted admin denied'; END IF;
    result := public.admin_update_user(actor,1,target,2,prefix||'-new@example.invalid','New',false,false,'reset-hash');
    IF NOT (result->>'ok')::boolean OR NOT EXISTS(SELECT 1 FROM public.users WHERE id=target AND password_hash='reset-hash' AND auth_version=3 AND NOT is_active AND NOT is_admin) THEN RAISE EXCEPTION 'Reset/disable/demotion failed'; END IF;
    IF (public.admin_list_users(target,3,prefix,0)->>'error') <> 'forbidden' THEN RAISE EXCEPTION 'Revoked admin still allowed'; END IF;

    IF (public.admin_delete_user(actor,1,actor,1)->>'error') <> 'self_protection' THEN RAISE EXCEPTION 'Self delete allowed'; END IF;
    IF (public.admin_update_user(actor,1,actor,1,prefix||'-admin@example.invalid','',false,true,NULL)->>'error') <> 'self_protection' THEN RAISE EXCEPTION 'Self disable allowed'; END IF;
    IF (public.admin_update_user(actor,1,actor,1,prefix||'-admin@example.invalid','',true,false,NULL)->>'error') <> 'self_protection' THEN RAISE EXCEPTION 'Self demotion allowed'; END IF;
    IF (public.admin_update_user(actor,1,actor,1,prefix||'-admin@example.invalid','',true,true,'reset')->>'error') <> 'self_protection' THEN RAISE EXCEPTION 'Self reset allowed'; END IF;
    IF NOT (public.admin_delete_user(actor,1,target,3)->>'ok')::boolean THEN RAISE EXCEPTION 'Delete failed'; END IF;
    IF (public.admin_get_user(actor,1,target)->>'error') <> 'not_found' OR EXISTS(SELECT 1 FROM public.users WHERE id=target) THEN RAISE EXCEPTION 'Deleted user remains'; END IF;
END;
$$;
ROLLBACK;
