-- Temporary immediate registration. Never label these email addresses as verified.
BEGIN;
ALTER TABLE public.users ADD COLUMN email_confirmed_at timestamptz;

CREATE FUNCTION public.register_user(p_email text, p_display_name text, p_password_hash text)
RETURNS jsonb LANGUAGE plpgsql SECURITY INVOKER SET search_path = ''
AS $$
DECLARE new_id uuid;
BEGIN
    INSERT INTO public.users (email, display_name, password_hash)
        VALUES (p_email, p_display_name, p_password_hash)
        ON CONFLICT (email) DO NOTHING RETURNING id INTO new_id;
    RETURN jsonb_build_object('created', new_id IS NOT NULL);
END;
$$;
REVOKE ALL ON FUNCTION public.register_user(text, text, text) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.register_user(text, text, text) TO service_role;

CREATE OR REPLACE FUNCTION public.confirm_registration(p_token_hash text, p_password_hash text)
RETURNS jsonb LANGUAGE plpgsql SECURITY INVOKER SET search_path = ''
AS $$
DECLARE pending public.registration_requests%ROWTYPE; new_id uuid;
BEGIN
    SELECT * INTO pending FROM public.registration_requests
        WHERE token_hash = p_token_hash AND expires_at > clock_timestamp() FOR UPDATE;
    IF NOT FOUND THEN RETURN jsonb_build_object('confirmed', false); END IF;
    INSERT INTO public.users (email, display_name, password_hash, email_confirmed_at)
        VALUES (pending.email, pending.display_name, p_password_hash, clock_timestamp())
        ON CONFLICT (email) DO NOTHING RETURNING id INTO new_id;
    DELETE FROM public.registration_requests WHERE token_hash = p_token_hash;
    RETURN jsonb_build_object('confirmed', new_id IS NOT NULL);
END;
$$;
COMMIT;
NOTIFY pgrst, 'reload schema';
