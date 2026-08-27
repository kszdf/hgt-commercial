<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 强密码规则：8-16 位，且由「大写字母 / 小写字母 / 数字 / 特殊字符」
 * 四类中【至少两种】组合（如 kszdf123456=小写+数字 合法；仅小写 不合法）。
 */
class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $s = (string) $value;
        $len = mb_strlen($s);
        if ($len < 8 || $len > 16) {
            $fail('密码长度需为 8-16 位。');
            return;
        }
        $cats = 0;
        if (preg_match('/[A-Z]/', $s)) {
            $cats++;
        }
        if (preg_match('/[a-z]/', $s)) {
            $cats++;
        }
        if (preg_match('/\d/', $s)) {
            $cats++;
        }
        if (preg_match('/[^A-Za-z0-9]/', $s)) {
            $cats++;
        }
        if ($cats < 2) {
            $fail('密码需由大写字母、小写字母、数字、特殊字符中至少两种组合。');
        }
    }
}
