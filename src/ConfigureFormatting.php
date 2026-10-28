<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Http\UrlGenerator;
use s9e\TextFormatter\Configurator;

final class ConfigureFormatting
{
    public function __construct(private UrlGenerator $url)
    {
    }

    public function __invoke(Configurator $config): void
    {
        $config->rendering->parameters['LM_BASE'] = $this->url->to('api')->path('media-library/files').'/';
        $group = $config->tags->add('LMSTICKERGROUP');
        $group->template = '<span class="MediaLibrary-stickerGroup"><xsl:apply-templates/></span>';
        $group->rules->breakParagraph();
        foreach (['media' => 'LMMEDIA', 'file' => 'LMFILE', 'memoji' => 'LMEMOJI', 'msticker' => 'LMSTICKER'] as $syntax => $name) {
            $tag = $config->tags->add($name);
            $tag->attributes->add('id')->filterChain->append('#regexp')->setRegexp('/^[a-f0-9]{32}$/D');
            $tag->attributes->add('post')->filterChain->append('#uint');
            $tag->attributes['post']->required = false;
            $tag->attributes->add('name')->required = false;
            foreach (['width', 'height'] as $dimension) {
                $tag->attributes->add($dimension)->filterChain->append('#uint');
                $tag->attributes[$dimension]->required = false;
            }
            $tag->rules->ignoreTags();
            $kind = match ($syntax) { 'memoji' => 'inline', 'msticker' => 'sticker', default => 'original' };
            $class = match ($syntax) { 'memoji' => 'emoji', 'msticker' => 'stickerImage', default => 'image' };
            $image = '<img class="MediaLibrary-'.$class.'" src="{$LM_BASE}{@id}?post={@post}&amp;variant='.$kind.'" alt="{@name}" width="{@width}" height="{@height}" loading="lazy" decoding="async"/>';
            $tag->template = match ($syntax) {
                'file' => '<a class="MediaLibrary-file" href="{$LM_BASE}{@id}?post={@post}" rel="nofollow"><xsl:choose><xsl:when test="@name"><xsl:value-of select="@name"/></xsl:when><xsl:otherwise>附件</xsl:otherwise></xsl:choose></a>',
                'msticker' => '<span class="MediaLibrary-sticker">'.$image.'</span>',
                default => $image,
            };
            if ($syntax === 'msticker') {
                $tag->rules->breakParagraph();
            }
            $config->Preg->match('/\['.$syntax.'=(?<id>[a-f0-9]{32})\]/', $name);
        }
    }
}
