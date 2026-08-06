<?php

namespace App\Services;

/**
 * 平台注册表访问层（Laravel 侧）。
 * 单一事实源为 config/platforms.php；本类提供类型安全的查询方法，
 * 避免各处散落硬编码的平台枚举（防止选题 / 出片 / 发布三处分裂）。
 */
class PlatformRegistry
{
    /** 全部平台（key => 定义数组） */
    public static function all(): array
    {
        return config('platforms.platforms', []);
    }

    /** 进入「智能选题」子集的平台：key => 中文 label */
    public static function topicList(): array
    {
        return collect(self::all())
            ->filter(fn ($p) => ($p['topic'] ?? false))
            ->mapWithKeys(fn ($p, $k) => [$k => $p['label']])
            ->all();
    }

    /** 出片分辨率 [宽, 高]，未知返回 null */
    public static function spec(string $key): ?array
    {
        return self::all()[$key]['spec'] ?? null;
    }

    /** 中文展示名，未知返回 null */
    public static function label(string $key): ?string
    {
        return self::all()[$key]['label'] ?? null;
    }

    /** 是否进入选题子集 */
    public static function isTopic(string $key): bool
    {
        return (bool) (self::all()[$key]['topic'] ?? false);
    }

    /** 校验 key 是否合法平台 */
    public static function isValid(string $key): bool
    {
        return isset(self::all()[$key]);
    }
}
