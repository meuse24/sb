-- Run as migration user after 003_registration_without_confirmation.sql. No test data is committed.
BEGIN;
DO $$
DECLARE browser_role text;
BEGIN
    FOREACH browser_role IN ARRAY ARRAY['anon', 'authenticated'] LOOP
        IF has_table_privilege(browser_role, 'public.registration_requests', 'SELECT')
            OR has_table_privilege(browser_role, 'public.registration_requests', 'INSERT')
            OR has_table_privilege(browser_role, 'public.registration_requests', 'UPDATE')
            OR has_table_privilege(browser_role, 'public.registration_requests', 'DELETE')
            OR has_function_privilege(browser_role, 'public.prepare_registration(text,text,text)', 'EXECUTE')
            OR has_function_privilege(browser_role, 'public.confirm_registration(text,text)', 'EXECUTE')
            OR has_function_privilege(browser_role, 'public.register_user(text,text,text)', 'EXECUTE') THEN
            RAISE EXCEPTION 'Unexpected registration privileges for %', browser_role;
        END IF;
    END LOOP;
    IF NOT (SELECT relrowsecurity FROM pg_class WHERE oid = 'public.registration_requests'::regclass) THEN
        RAISE EXCEPTION 'Registration RLS missing';
    END IF;
END;
$$;
SET LOCAL ROLE service_role;
DO $$
DECLARE
    test_email text := 'registration-' || gen_random_uuid() || '@example.invalid';
    hash_a text := md5(gen_random_uuid()::text) || md5(gen_random_uuid()::text);
    hash_b text := md5(gen_random_uuid()::text) || md5(gen_random_uuid()::text);
    direct_email text := 'direct-' || gen_random_uuid() || '@example.invalid';
BEGIN
    IF NOT (public.prepare_registration(test_email, 'Test', hash_a)->>'accepted')::boolean THEN
        RAISE EXCEPTION 'Preparation failed';
    END IF;
    IF EXISTS (SELECT 1 FROM public.users WHERE email = test_email) THEN
        RAISE EXCEPTION 'User created before confirmation';
    END IF;
    IF (public.prepare_registration(test_email, 'Overwrite', hash_b)->>'accepted')::boolean THEN
        RAISE EXCEPTION 'Cooldown bypassed';
    END IF;
    UPDATE public.registration_requests SET expires_at = clock_timestamp() - interval '1 second' WHERE email = test_email;
    IF (public.confirm_registration(hash_a, 'expired')->>'confirmed')::boolean THEN
        RAISE EXCEPTION 'Expired token accepted';
    END IF;
    UPDATE public.registration_requests SET expires_at = clock_timestamp() + interval '1 minute' WHERE email = test_email;
    IF NOT (public.confirm_registration(hash_a, 'original-hash')->>'confirmed')::boolean THEN
        RAISE EXCEPTION 'Confirmation failed';
    END IF;
    IF (public.confirm_registration(hash_a, 'overwrite')->>'confirmed')::boolean THEN
        RAISE EXCEPTION 'Token replay accepted';
    END IF;
    IF (public.prepare_registration(test_email, 'Overwrite', hash_b)->>'accepted')::boolean THEN
        RAISE EXCEPTION 'Existing user accepted';
    END IF;
    -- Even a request that predates a separately created account may not replace it.
    INSERT INTO public.registration_requests (token_hash, email, expires_at)
        VALUES (hash_b, test_email, clock_timestamp() + interval '1 minute');
    IF (public.confirm_registration(hash_b, 'overwrite')->>'confirmed')::boolean THEN
        RAISE EXCEPTION 'Existing user overwritten';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM public.users WHERE email = test_email AND password_hash = 'original-hash' AND display_name = 'Test' AND email_confirmed_at IS NOT NULL) THEN
        RAISE EXCEPTION 'Original account modified';
    END IF;
    IF NOT (public.register_user(direct_email, 'Direct', 'direct-hash')->>'created')::boolean THEN
        RAISE EXCEPTION 'Direct registration failed';
    END IF;
    IF (public.register_user(direct_email, 'Overwrite', 'overwrite')->>'created')::boolean THEN
        RAISE EXCEPTION 'Duplicate direct registration accepted';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM public.users WHERE email = direct_email AND display_name = 'Direct'
        AND password_hash = 'direct-hash' AND email_confirmed_at IS NULL) THEN
        RAISE EXCEPTION 'Direct registration metadata incorrect';
    END IF;
END;
$$;
ROLLBACK;
