<?php
declare(strict_types=1);

return [
    'supabase_url' => 'https://YOUR_PROJECT.supabase.co',
    // Backend only. Never expose this key in HTML, JavaScript or Git.
    'supabase_secret_key' => 'sb_secret_REPLACE_ME',
    // Temporary: new accounts can sign in immediately without confirmation mail.
    'registration_require_email_confirmation' => false,
];
