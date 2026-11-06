-- Run ONCE in Supabase SQL Editor (as postgres). Existing auth.users is untouched.
BEGIN;
CREATE TABLE public.users (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email varchar(254) NOT NULL UNIQUE CHECK (email = lower(trim(email))),
    password_hash varchar(255) NOT NULL,
    display_name varchar(100) NOT NULL DEFAULT '',
    is_active boolean NOT NULL DEFAULT true,
    auth_version integer NOT NULL DEFAULT 1 CHECK (auth_version > 0),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE public.login_attempts (
    bucket varchar(64) PRIMARY KEY,
    attempts integer NOT NULL CHECK (attempts > 0),
    window_started bigint NOT NULL
);
CREATE INDEX login_attempts_window_idx ON public.login_attempts (window_started);

-- Password hashes and login counters must never be readable using browser keys.
ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.login_attempts ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.users, public.login_attempts FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.users, public.login_attempts TO service_role;
-- No policies for anon/authenticated. Only the trusted PHP backend uses service_role.

-- One atomic database transaction for both limits, including parallel requests.
CREATE FUNCTION public.consume_login_attempt(p_email_bucket text, p_ip_bucket text)
RETURNS jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = ''
AS $$
DECLARE
    now_seconds bigint := floor(extract(epoch FROM clock_timestamp()));
    email_attempts integer;
    ip_attempts integer;
BEGIN
    IF p_email_bucket !~ '^[a-f0-9]{64}$' OR p_ip_bucket !~ '^[a-f0-9]{64}$'
        OR p_email_bucket IS NULL OR p_ip_bucket IS NULL THEN
        RAISE EXCEPTION 'Invalid bucket';
    END IF;
    INSERT INTO public.login_attempts AS current (bucket, attempts, window_started)
    VALUES (p_email_bucket, 1, now_seconds)
    ON CONFLICT (bucket) DO UPDATE SET
        attempts = CASE WHEN current.window_started <= now_seconds - 900 THEN 1 ELSE current.attempts + 1 END,
        window_started = CASE WHEN current.window_started <= now_seconds - 900 THEN now_seconds ELSE current.window_started END
    RETURNING attempts INTO email_attempts;

    INSERT INTO public.login_attempts AS current (bucket, attempts, window_started)
    VALUES (p_ip_bucket, 1, now_seconds)
    ON CONFLICT (bucket) DO UPDATE SET
        attempts = CASE WHEN current.window_started <= now_seconds - 900 THEN 1 ELSE current.attempts + 1 END,
        window_started = CASE WHEN current.window_started <= now_seconds - 900 THEN now_seconds ELSE current.window_started END
    RETURNING attempts INTO ip_attempts;

    DELETE FROM public.login_attempts WHERE window_started < now_seconds - 86400;
    RETURN jsonb_build_object('allowed', email_attempts <= 10 AND ip_attempts <= 50);
END;
$$;
REVOKE ALL ON FUNCTION public.consume_login_attempt(text, text) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.consume_login_attempt(text, text) TO service_role;
COMMIT;
NOTIFY pgrst, 'reload schema';
