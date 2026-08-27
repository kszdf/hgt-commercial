<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * 预置默认租户 huigentang（慧根堂）与管理员，
     * 使平台开箱即可登录测试：28642235@qq.com / admin888
     */
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'huigentang'],
            [
                'name' => '慧根堂',
                'plan' => 'pro',
                'status' => 'active',
                'quota_monthly' => 200,
                'default_avatar' => 'BGZSP20260721_t18_silent.mp4',
                'default_male_voice' => 'cosyvoice-v3-plus-zhangc2-28a7c3541e1c45518a03046c11baeb1d',
                'default_female_voice' => 'cosyvoice-v3-plus-jiangnv3-991b204c1d564ac7a60f0cb9a8fd78bd',
            ]
        );
        // 幂等更新（已存在时也确保额度/音色正确）
        $tenant->update([
            'plan' => 'pro',
            'quota_monthly' => 200,
            'default_male_voice' => 'cosyvoice-v3-plus-zhangc2-28a7c3541e1c45518a03046c11baeb1d',
            'default_female_voice' => 'cosyvoice-v3-plus-jiangnv3-991b204c1d564ac7a60f0cb9a8fd78bd',
        ]);

        // 超级管理员账号：全局超管判定靠 tenant_id === null，故 tenant_id 必须显式设为 null（否则只是普通租户成员，看不了监控大盘）。
        // 密码从 .env 的 ADMIN_PASSWORD 读取，生产必须配置强密码；未配置时回退 admin888（仅本地测试）。
        $adminEmail = '28642235@qq.com';
        $admin = User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'tenant_id' => null,
                'name' => '张老师',
                'email' => $adminEmail,
                'password' => Hash::make(env('ADMIN_PASSWORD', 'admin888')),
                'email_verified_at' => now(),
            ]
        );

        $this->call(QcRulesSeeder::class);
    }
}
