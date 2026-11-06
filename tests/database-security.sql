-- Run as the migration user AFTER 001_users.sql. All test changes are rolled back.
BEGIN;
DO $$
DECLARE
    browser_role text;
BEGIN
    FOREACH browser_role IN ARRAY ARRAY['anon', 'authenticated'] LOOP
        IF has_table_privilege(browser_role, 'public.users', 'SELECT')
            OR has_table_privilege(browser_role, 'public.users', 'INSERT')
            OR has_table_privilege(browser_role, 'public.users', 'UPDATE')
            OR has_table_privilege(browser_role, 'public.users', 'DELETE')
            OR has_table_privilege(browser_role, 'public.login_attempts', 'SELECT')
            OR has_table_privilege(browser_role, 'public.login_attempts', 'INSERT')
            OR has_table_privilege(browser_role, 'public.login_attempts', 'UPDATE')
            OR has_table_privilege(browser_role, 'public.login_attempts', 'DELETE')
            OR has_function_privilege(browser_role, 'public.consume_login_attempt(text,text)', 'EXECUTE') THEN
            RAISE EXCEPTION 'Unexpected browser privileges for %', browser_role;
        END IF;
    END LOOP;
    IF EXISTS (SELECT 1 FROM pg_class WHERE oid IN ('public.users'::regclass, 'public.login_attempts'::regclass)
        AND NOT relrowsecurity) THEN
        RAISE EXCEPTION 'RLS missing';
    END IF;
END;
$$;

SET LOCAL ROLE service_role;
DO $$
DECLARE
    i integer;
    result jsonb;
    test_id uuid;
    test_email text := 'transaction-' || gen_random_uuid() || '@example.invalid';
BEGIN
    INSERT INTO public.users (email, password_hash, display_name)
        VALUES (test_email, 'test-only-hash', '<script>test</script>') RETURNING id INTO test_id;
    IF NOT EXISTS (SELECT 1 FROM public.users WHERE id = test_id AND password_hash = 'test-only-hash') THEN
        RAISE EXCEPTION 'Backend user access failed';
    END IF;
    BEGIN
        INSERT INTO public.users (email, password_hash) VALUES (test_email, 'duplicate');
        RAISE EXCEPTION 'Duplicate email accepted';
    EXCEPTION WHEN unique_violation THEN NULL;
    END;
    BEGIN
        INSERT INTO public.users (email, password_hash) VALUES ('UpperCase@example.invalid', 'invalid');
        RAISE EXCEPTION 'Non-normalized email accepted';
    EXCEPTION WHEN check_violation THEN NULL;
    END;

    DELETE FROM public.login_attempts WHERE bucket IN (repeat('a',64), repeat('b',64), repeat('c',64));
    FOR i IN 1..11 LOOP
        result := public.consume_login_attempt(repeat('a',64), repeat('b',64));
        IF (result->>'allowed')::boolean IS DISTINCT FROM (i <= 10) THEN
            RAISE EXCEPTION 'Email rate limit failed';
        END IF;
    END LOOP;
    UPDATE public.login_attempts SET window_started = floor(extract(epoch FROM clock_timestamp())) - 901
        WHERE bucket IN (repeat('a',64), repeat('b',64));
    IF NOT (public.consume_login_attempt(repeat('a',64),repeat('b',64))->>'allowed')::boolean THEN
        RAISE EXCEPTION 'Rate window reset failed';
    END IF;
    FOR i IN 1..51 LOOP
        result := public.consume_login_attempt(md5('ip-test:' || i)||md5('ip-test:' || i), repeat('c',64));
        IF (result->>'allowed')::boolean IS DISTINCT FROM (i <= 50) THEN
            RAISE EXCEPTION 'IP rate limit failed';
        END IF;
    END LOOP;
END;
$$;
ROLLBACK;
