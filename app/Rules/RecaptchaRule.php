<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

class RecaptchaRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secret = config('recaptcha.api_secret_key');
        if (!$secret || !is_string($value) || $value === '') {
            $fail('The CAPTCHA verification failed.');
            return;
        }

        try {
            $response = Http::asForm()->timeout(10)->post(
                'https://www.google.com/recaptcha/api/siteverify',
                [
                    'secret' => $secret,
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ]
            );

            if (!$response->successful() || !$response->json('success')) {
                $fail('The CAPTCHA verification failed.');
            }
        } catch (\Throwable $exception) {
            report($exception);
            $fail('The CAPTCHA service is temporarily unavailable.');
        }
    }
}
