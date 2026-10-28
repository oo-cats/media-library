<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Foundation\ValidationException;

final class Metadata
{
    /** Behaviour labels have one canonical representation: indexed boolean columns. */
    public static function tags(mixed $input): array
    {
        if (!is_array($input) || count($input) > 12) {
            throw new ValidationException(['tags' => '最多设置 12 个标签。']);
        }
        $tags = [];
        foreach ($input as $value) {
            if (!is_string($value)) {
                throw new ValidationException(['tags' => '标签必须是文字。']);
            }
            $name = mb_strtolower(trim($value));
            if (!preg_match('/^[\pL\pN][\pL\pN _-]{0,31}$/u', $name)) {
                throw new ValidationException(['tags' => '标签须为 1–32 字的文字、数字、空格、下划线或连字符。']);
            }
            $tags[$name] = true;
        }
        return [
            'public' => isset($tags['public']),
            'emoji' => isset($tags['emoji']),
            'categories' => array_values(array_diff(array_keys($tags), ['public', 'emoji'])),
        ];
    }

    public static function name(mixed $input): string
    {
        if (!is_string($input)) {
            throw new ValidationException(['name' => '请填写文件名。']);
        }
        $name = trim($input);
        if ($name === '' || mb_strlen($name) > 180 || preg_match('/[\x00-\x1f\x7f\/\\\\]/u', $name)) {
            throw new ValidationException(['name' => '文件名须为 1–180 字，不能包含路径分隔符或控制字符。']);
        }
        return $name;
    }
}
