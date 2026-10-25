<?php

namespace App\Models\Concerns;

/*
 * Sign-in tokens for the staff mobile app (Laravel Sanctum). Until Sanctum is
 * installed (composer require laravel/sanctum) this does nothing, so the
 * website keeps working; the mobile API switches itself off in the meantime.
 */
if (trait_exists(\Laravel\Sanctum\HasApiTokens::class)) {
    trait MobileTokens
    {
        use \Laravel\Sanctum\HasApiTokens;
    }
} else {
    trait MobileTokens
    {
    }
}
