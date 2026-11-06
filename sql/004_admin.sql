BEGIN;
ALTER TABLE public.users ADD COLUMN is_admin boolean NOT NULL DEFAULT false;

-- Return only an explicit allowlist, never password hashes or future private fields.
CREATE FUNCTION public.admin_user_json(p_user public.users) RETURNS jsonb
LANGUAGE sql IMMUTABLE SECURITY INVOKER SET search_path = '' AS $$
    SELECT jsonb_build_object('id', p_user.id, 'email', p_user.email,
        'display_name', p_user.display_name, 'is_active', p_user.is_active,
        'is_admin', p_user.is_admin, 'auth_version', p_user.auth_version,
        'created_at', p_user.created_at, 'email_confirmed_at', p_user.email_confirmed_at);
$$;

-- Hold a shared row lock until this request commits, so role revocation cannot
-- pass between authorization and the protected read/write operation.
CREATE FUNCTION public.admin_actor_valid(p_actor_id uuid, p_actor_version integer) RETURNS boolean
LANGUAGE plpgsql SECURITY INVOKER SET search_path = '' AS $$
BEGIN
    PERFORM 1 FROM public.users WHERE id = p_actor_id AND is_active AND is_admin
        AND auth_version = p_actor_version FOR SHARE;
    RETURN FOUND;
END;
$$;

CREATE FUNCTION public.admin_list_users(p_actor_id uuid, p_actor_version integer,
    p_search text DEFAULT '', p_offset integer DEFAULT 0) RETURNS jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = '' AS $$
DECLARE result jsonb; total bigint;
BEGIN
    IF NOT public.admin_actor_valid(p_actor_id, p_actor_version) THEN
        RETURN jsonb_build_object('ok', false, 'error', 'forbidden');
    END IF;
    IF p_offset IS NULL OR p_offset < 0 OR p_search IS NULL OR char_length(p_search) > 100 THEN
        RETURN jsonb_build_object('ok', false, 'error', 'invalid');
    END IF;
    SELECT count(*) INTO total FROM public.users
        WHERE strpos(lower(email), lower(p_search)) > 0 OR strpos(lower(display_name), lower(p_search)) > 0;
    SELECT coalesce(jsonb_agg(page.data), '[]'::jsonb) INTO result
        FROM (SELECT public.admin_user_json(u) AS data FROM public.users AS u
            WHERE strpos(lower(email), lower(p_search)) > 0 OR strpos(lower(display_name), lower(p_search)) > 0
            ORDER BY created_at, id LIMIT 25 OFFSET p_offset) AS page;
    RETURN jsonb_build_object('ok', true, 'users', result, 'total', total);
END;
$$;

CREATE FUNCTION public.admin_get_user(p_actor_id uuid, p_actor_version integer, p_target_id uuid) RETURNS jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = '' AS $$
DECLARE target public.users%ROWTYPE;
BEGIN
    IF NOT public.admin_actor_valid(p_actor_id, p_actor_version) THEN
        RETURN jsonb_build_object('ok', false, 'error', 'forbidden');
    END IF;
    SELECT * INTO target FROM public.users WHERE id = p_target_id;
    IF NOT FOUND THEN RETURN jsonb_build_object('ok', false, 'error', 'not_found'); END IF;
    RETURN jsonb_build_object('ok', true, 'user', public.admin_user_json(target));
END;
$$;

CREATE FUNCTION public.admin_create_user(p_actor_id uuid, p_actor_version integer, p_email text,
    p_display_name text, p_password_hash text, p_is_active boolean, p_is_admin boolean) RETURNS jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = '' AS $$
DECLARE target public.users%ROWTYPE;
BEGIN
    -- Serialize admin mutations so concurrent demotions/deletions cannot remove
    -- all administrators or authorize using a role that has just been revoked.
    PERFORM pg_advisory_xact_lock(61423941004);
    IF NOT public.admin_actor_valid(p_actor_id, p_actor_version) THEN
        RETURN jsonb_build_object('ok', false, 'error', 'forbidden');
    END IF;
    IF p_password_hash IS NULL OR p_password_hash = '' THEN
        RETURN jsonb_build_object('ok', false, 'error', 'invalid');
    END IF;
    INSERT INTO public.users (email, display_name, password_hash, is_active, is_admin)
        VALUES (p_email, p_display_name, p_password_hash, p_is_active, p_is_admin) RETURNING * INTO target;
    RETURN jsonb_build_object('ok', true, 'user', public.admin_user_json(target));
EXCEPTION
    WHEN unique_violation THEN RETURN jsonb_build_object('ok', false, 'error', 'duplicate');
    WHEN check_violation OR not_null_violation OR string_data_right_truncation THEN
        RETURN jsonb_build_object('ok', false, 'error', 'invalid');
END;
$$;

CREATE FUNCTION public.admin_update_user(p_actor_id uuid, p_actor_version integer,
    p_target_id uuid, p_expected_version integer, p_email text, p_display_name text,
    p_is_active boolean, p_is_admin boolean, p_password_hash text DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = '' AS $$
DECLARE target public.users%ROWTYPE;
BEGIN
    PERFORM pg_advisory_xact_lock(61423941004);
    IF NOT public.admin_actor_valid(p_actor_id, p_actor_version) THEN
        RETURN jsonb_build_object('ok', false, 'error', 'forbidden');
    END IF;
    SELECT * INTO target FROM public.users WHERE id = p_target_id FOR UPDATE;
    IF NOT FOUND THEN RETURN jsonb_build_object('ok', false, 'error', 'not_found'); END IF;
    IF p_expected_version IS NULL OR target.auth_version <> p_expected_version OR target.auth_version >= 2147483647 THEN
        RETURN jsonb_build_object('ok', false, 'error', 'conflict');
    END IF;
    IF p_actor_id = p_target_id AND (NOT p_is_active OR NOT p_is_admin OR p_password_hash IS NOT NULL) THEN
        RETURN jsonb_build_object('ok', false, 'error', 'self_protection');
    END IF;
    IF target.is_admin AND target.is_active AND (NOT p_is_admin OR NOT p_is_active)
        AND (SELECT count(*) FROM public.users WHERE is_admin AND is_active) <= 1 THEN
        RETURN jsonb_build_object('ok', false, 'error', 'last_admin');
    END IF;
    IF p_password_hash = '' THEN RETURN jsonb_build_object('ok', false, 'error', 'invalid'); END IF;
    UPDATE public.users SET email = p_email, display_name = p_display_name,
        is_active = p_is_active, is_admin = p_is_admin,
        password_hash = coalesce(p_password_hash, target.password_hash),
        email_confirmed_at = CASE WHEN email = p_email THEN email_confirmed_at ELSE NULL END,
        auth_version = auth_version + 1 WHERE id = p_target_id RETURNING * INTO target;
    RETURN jsonb_build_object('ok', true, 'user', public.admin_user_json(target));
EXCEPTION
    WHEN unique_violation THEN RETURN jsonb_build_object('ok', false, 'error', 'duplicate');
    WHEN check_violation OR not_null_violation OR string_data_right_truncation THEN
        RETURN jsonb_build_object('ok', false, 'error', 'invalid');
END;
$$;

CREATE FUNCTION public.admin_delete_user(p_actor_id uuid, p_actor_version integer,
    p_target_id uuid, p_expected_version integer) RETURNS jsonb
LANGUAGE plpgsql SECURITY INVOKER SET search_path = '' AS $$
DECLARE target public.users%ROWTYPE;
BEGIN
    PERFORM pg_advisory_xact_lock(61423941004);
    IF NOT public.admin_actor_valid(p_actor_id, p_actor_version) THEN
        RETURN jsonb_build_object('ok', false, 'error', 'forbidden');
    END IF;
    SELECT * INTO target FROM public.users WHERE id = p_target_id FOR UPDATE;
    IF NOT FOUND THEN RETURN jsonb_build_object('ok', false, 'error', 'not_found'); END IF;
    IF p_expected_version IS NULL OR target.auth_version <> p_expected_version THEN
        RETURN jsonb_build_object('ok', false, 'error', 'conflict');
    END IF;
    IF p_actor_id = p_target_id THEN RETURN jsonb_build_object('ok', false, 'error', 'self_protection'); END IF;
    IF target.is_admin AND target.is_active
        AND (SELECT count(*) FROM public.users WHERE is_admin AND is_active) <= 1 THEN
        RETURN jsonb_build_object('ok', false, 'error', 'last_admin');
    END IF;
    DELETE FROM public.users WHERE id = p_target_id;
    RETURN jsonb_build_object('ok', true);
END;
$$;

REVOKE ALL ON FUNCTION public.admin_user_json(public.users), public.admin_actor_valid(uuid, integer),
    public.admin_list_users(uuid, integer, text, integer), public.admin_get_user(uuid, integer, uuid),
    public.admin_create_user(uuid, integer, text, text, text, boolean, boolean),
    public.admin_update_user(uuid, integer, uuid, integer, text, text, boolean, boolean, text),
    public.admin_delete_user(uuid, integer, uuid, integer) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.admin_user_json(public.users), public.admin_actor_valid(uuid, integer),
    public.admin_list_users(uuid, integer, text, integer), public.admin_get_user(uuid, integer, uuid),
    public.admin_create_user(uuid, integer, text, text, text, boolean, boolean),
    public.admin_update_user(uuid, integer, uuid, integer, text, text, boolean, boolean, text),
    public.admin_delete_user(uuid, integer, uuid, integer) TO service_role;
COMMIT;
NOTIFY pgrst, 'reload schema';
