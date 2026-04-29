<?php

namespace App\Services;

use App\Models\GalnetNews;
use Illuminate\Support\Facades\Http;

class GalnetNewsService extends ApiService
{
    /**
     * Import galnet news articles.
     */
    public function import(): int
    {
        $response = Http::get(config('elite.galnet.json'));
        $content = $this->getContents($response);

        if ($content) {
            // Get the last added article
            $lastArticle = GalnetNews::orderBy('order_added', 'desc')->first();
            $lastArticleOrder = $lastArticle ? $lastArticle->order_added : 0;

            $i = 0;
            foreach (array_reverse($content->data) as $article) {
                $article = $article->attributes;
                if (GalnetNews::whereTitle($article->title)->exists()) {
                    continue;
                }

                $article = GalnetNews::updateOrCreate(['title' => $article->title], [
                    'title' => $article->title,
                    'content' => $article->body->processed,
                    'uploaded_at' => $article->field_galnet_date,
                    'order_added' => ++$lastArticleOrder,
                    'banner_image' => ['/images/galnet/sunrise.jpg', '/images/galnet/helios.jpg', '/images/galnet/nebula.jpg'][$i % 3],
                ]);

                $article->audio_file = "/audio/galnet-article-id-{$article->id}.mp3";
                $article->save();

                $i++;
            }

            return $i;
        }

        return 0;
    }
}
