<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;

final class ImageProfiles
{
    public const FIELDS = [
        'maxFileMb' => ['label' => '表情原文件上限', 'default' => 5, 'min' => 1, 'max' => 25, 'unit' => 'MiB'],
        'maxPixels' => ['label' => '原图总像素上限', 'default' => 16000000, 'min' => 65536, 'max' => 16000000],
        'maxEdge' => ['label' => '原图单边上限', 'default' => 10000, 'min' => 128, 'max' => 10000, 'unit' => 'px'],
        'pickerEdge' => ['label' => '选择器处理图边长', 'default' => 128, 'min' => 32, 'max' => 256, 'unit' => 'px'],
        'inlineEdge' => ['label' => '行内处理图边长', 'default' => 96, 'min' => 32, 'max' => 512, 'unit' => 'px'],
        'stickerEdge' => ['label' => '贴纸处理图边长', 'default' => 480, 'min' => 128, 'max' => 2048, 'unit' => 'px'],
        'pickerKb' => ['label' => '选择器处理图大小', 'default' => 40, 'min' => 4, 'max' => 128, 'unit' => 'KiB'],
        'inlineKb' => ['label' => '行内处理图大小', 'default' => 80, 'min' => 8, 'max' => 512, 'unit' => 'KiB'],
        'stickerKb' => ['label' => '贴纸处理图大小', 'default' => 300, 'min' => 32, 'max' => 2048, 'unit' => 'KiB'],
        'quality' => ['label' => 'WebP / JPEG 目标质量', 'default' => 80, 'min' => 1, 'max' => 100],
        'qualityFloor' => ['label' => '有损质量下限', 'default' => 60, 'min' => 1, 'max' => 100],
        'pngLevel' => ['label' => 'PNG 压缩等级', 'default' => 6, 'min' => 0, 'max' => 9],
        'attempts' => ['label' => '单用途最大编码次数', 'default' => 6, 'min' => 1, 'max' => 6],
        'format' => ['label' => '处理图输出格式', 'default' => 'auto', 'options' => ['auto' => '自动 WebP', 'webp' => 'WebP', 'png' => 'PNG', 'jpeg' => 'JPEG（透明图拒绝）']],
        'gifPolicy' => ['label' => 'GIF 表情策略', 'default' => 'static', 'options' => ['static' => '静态首帧', 'animated' => '动态 GIF（保留动画）']],
        'gifMaxKb' => ['label' => 'GIF 原文件上限', 'default' => 1024, 'min' => 16, 'max' => 5120, 'unit' => 'KiB'],
        'gifMaxEdge' => ['label' => 'GIF 画布最长边', 'default' => 512, 'min' => 32, 'max' => 2048, 'unit' => 'px'],
        'gifMaxFrames' => ['label' => 'GIF 最大帧数', 'default' => 120, 'min' => 2, 'max' => 500],
        'gifMaxPixels' => ['label' => 'GIF 画布像素 × 帧数上限', 'default' => 16000000, 'min' => 65536, 'max' => 64000000],
        'gifMaxDuration' => ['label' => 'GIF 单轮动画时长上限', 'default' => 30, 'min' => 1, 'max' => 120, 'unit' => '秒'],
        'maxOccurrences' => ['label' => '单帖图片表情总次数', 'default' => 100, 'min' => 10, 'max' => 300],
        'maxStickers' => ['label' => '单帖贴纸次数', 'default' => 20, 'min' => 1, 'max' => 50],
    ];

    public function __construct(private SettingsRepositoryInterface $settings) {}

    public function enabled(): bool
    {
        return $this->settings->get('local-media-library.emoji.enabled', '0') === '1';
    }

    public function values(): array
    {
        $result = [];
        foreach (self::FIELDS as $key => $field) {
            $value = $this->settings->get('local-media-library.emoji.'.$key, $field['default']);
            $result[$key] = isset($field['options'])
                ? (array_key_exists((string) $value, $field['options']) ? $value : $field['default'])
                : (is_numeric($value) ? max($field['min'], min($field['max'], (int) $value)) : $field['default']);
        }
        $result['qualityFloor'] = min($result['qualityFloor'], $result['quality']);
        $result['maxStickers'] = min($result['maxStickers'], $result['maxOccurrences']);
        return $result;
    }

    public function validate(array $values): array
    {
        foreach ($values as $key => $value) {
            $field = self::FIELDS[$key] ?? null;
            if (!$field || (isset($field['options'])
                ? !is_string($value) || !array_key_exists($value, $field['options'])
                : !is_numeric($value) || (float) $value !== (float) (int) $value || $value < $field['min'] || $value > $field['max'])) {
                throw new ValidationException(['settings' => '无效的图片处理设置：'.$key]);
            }
            if (!isset($field['options'])) {
                $values[$key] = (int) $value;
            }
        }
        $merged = array_replace($this->values(), $values);
        if ($merged['qualityFloor'] > $merged['quality'] || $merged['maxStickers'] > $merged['maxOccurrences']) {
            throw new ValidationException(['settings' => '质量下限或贴纸次数超过相应上限。']);
        }
        return $values;
    }

    public function save(array $values): void
    {
        foreach ($this->validate($values) as $key => $value) {
            $this->settings->set('local-media-library.emoji.'.$key, (string) $value);
        }
    }

    public function hash(array $values): string
    {
        unset($values['maxFileMb'], $values['maxPixels'], $values['maxEdge'], $values['maxOccurrences'], $values['maxStickers']);
        return hash('sha256', 'gd-static-v1:'.json_encode($values, JSON_THROW_ON_ERROR));
    }
}
