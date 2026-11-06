-- Run once, after 001_users.sql. Existing users remain valid.
BEGIN;
CREATE TABLE public.registration_requests (
    token_hash varchar(64) PRIMARY KEY CHECK (token_hash ~ '^[a-f0-9]{64}$'),
    email varchar(254) NOT NULL UNIQUE CHECK (email = lower(trim(email))),
    display_name varchar(100) NOT NULL DEFAULT '',
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at timestamptz NOT NULL
);
ALTER TABLE public.registration_requests ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.registration_requests FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.registration_requests TO service_role;

CREATE FUNCTION public.prepare_registration(p_email text, p_display_name text, p_token_hash text)
RETURNS jsonb LANGUAGE plpgsql SECURITY INVOKER SET search_path = ''
AS $$
DECLARE accepted_hash text;
BEGIN
    IF p_token_hash IS NULL OR p_token_hash !~ '^[a-f0-9]{64}$' THEN
        RAISE EXCEPTION 'Invalid token';
    END IF;
    DELETE FROM public.registration_requests WHERE expires_at <= clock_timestamp();
    IF EXISTS (SELECT 1 FROM public.users WHERE email = p_email) THEN
        RETURN jsonb_build_object('accepted', false);
    END IF;
    INSERT INTO public.registration_requests AS pending (email, display_name, token_hash, expires_at)
        VALUES (p_email, p_display_name, p_token_hash, clock_timestamp() + interval '30 minutes')
    ON CONFLICT (email) DO UPDATE SET
        display_name = EXCLUDED.display_name, token_hash = EXCLUDED.token_hash,
        created_at = clock_timestamp(), expires_at = EXCLUDED.expires_at
    WHERE pending.created_at <= clock_timestamp() - interval '60 seconds'
    RETURNING token_hash INTO accepted_hash;
    RETURN jsonb_build_object('accepted', accepted_hash IS NOT NULL);
END;
$$;

CREATE FUNCTION public.confirm_registration(p_token_hash text, p_password_hash text)
RETURNS jsonb LANGUAGE plpgsql SECURITY INVOKER SET search_path = ''
AS $$
DECLARE pending public.registration_requests%ROWTYPE; new_id uuid;
BEGIN
    SELECT * INTO pending FROM public.registration_requests
        WHERE token_hash = p_token_hash AND expires_at > clock_timestamp() FOR UPDATE;
    IF NOT FOUND THEN RETURN jsonb_build_object('confirmed', false); END IF;
    INSERT INTO public.users (email, display_name, password_hash)
        VALUES (pending.email, pending.display_name, p_password_hash)
        ON CONFLICT (email) DO NOTHING RETURNING id INTO new_id;
    DELETE FROM public.registration_requests WHERE token_hash = p_token_hash;
    RETURN jsonb_build_object('confirmed', new_id IS NOT NULL);
END;
$$;
REVOKE ALL ON FUNCTION public.prepare_registration(text, text, text) FROM PUBLIC, anon, authenticated;
REVOKE ALL ON FUNCTION public.confirm_registration(text, text) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.prepare_registration(text, text, text) TO service_role;
GRANT EXECUTE ON FUNCTION public.confirm_registration(text, text) TO service_role;
COMMIT;
NOTIFY pgrst, 'reload schema';
