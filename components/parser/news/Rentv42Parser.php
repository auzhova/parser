<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей с сайта https://www.rentv42.com/
 */
class Rentv42Parser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'https://www.rentv42.com/';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL;
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $url = $newsUrl;
            $contentPage = $this->getPageContent($url);
            $itemCrawler = new Crawler($contentPage);

            $title = $itemCrawler->filterXPath("//header/h1[@class='entry-title']")->text();
            $date = $this->getDate($itemCrawler->filterXPath("//time[@class='entry-date updated td-module-date']")->text());
            $image = null;
            $imgSrc = $itemCrawler->filterXPath("//*[@class='td-post-content td-pb-padding-side']/*/img");
            if ($imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            } elseif ($imgSrc = $itemCrawler->filterXPath("//*[@class='td-post-content']/*/img") && $imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            }
            $description = $this->clearText($itemCrawler->filterXPath("//*[@class='td-post-content td-pb-padding-side']/p")->text());

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $newsContent = $itemCrawler->filterXPath("//*[@class='td-post-content td-pb-padding-side']");
            if (!$newsContent->getNode(0)) {
                $newsContent = $itemCrawler->filterXPath("//*[@class='td-post-content']");
            }
            if ($newsContent->getNode(0)) {
                $newsContent = $newsContent->html();
            }

            $newContentCrawler = new Crawler($newsContent);
            if (!$newContentCrawler->getNode(0)) {
                continue;
            }

            $newContentCrawler = $newContentCrawler->filterXPath('//body')->children();

            foreach ($newContentCrawler as $content) {
                foreach ($content->childNodes as $childNode) {
                    $nodeValue = $this->clearText($childNode->nodeValue, [$post->description]);
                    if ($childNode->nodeName == 'iframe') {
                        $src = $childNode->getAttribute('src');
                        $youId = basename(parse_url($src, PHP_URL_PATH));
                        if(($i = strpos($youId, '%')) !== false){
                            $youId = substr($youId, 0, $i);
                        }
                        if (strpos($src, 'youtube') !== false) {

                            $this->addItemPost($post, NewsPostItem::TYPE_VIDEO, $childNode->getAttribute('title'), null, null, null, $youId);

                        }
                    } elseif ($nodeValue && strpos($nodeValue, 'var ') === false && strpos($nodeValue, 'adsbygoogle') === false && strpos($nodeValue, '&#10') === false) {

                        $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $nodeValue);

                    }
                }
            }

            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * @param NewsPost $post
     * @param int $type
     * @param string|null $text
     * @param string|null $image
     * @param string|null $link
     * @param int|null $headerLevel
     * @param string|null $youtubeId
     */
    protected function addItemPost(NewsPost $post, int $type, string $text = null, string $image = null,
                                   string $link = null, int $headerLevel = null, string $youtubeId = null): void
    {
        $post->addItem(
            new NewsPostItem(
                $type,
                $text,
                $image,
                $link,
                $headerLevel,
                $youtubeId
            ));
    }

    /**
     *
     * @param string $url
     *
     * @return string
     */
    protected function getHeadUrl($url): string
    {
        return strpos($url, 'http') === false
            ? self::SITE_URL . $url
            : $url;
    }

    /**
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date): string
    {
        $ruMonths = ['Янв', 'Фев', 'Мар', 'Апр', 'Мая', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
        $enMonths = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
        $newDate = new DateTime(str_ireplace($ruMonths, $enMonths, $date));
        $newDate->setTimezone(new DateTimeZone("UTC"));
        return $newDate->format("Y-m-d H:i:s");
    }

    /**
     * Получение списка ссылок на страницы новостей
     *
     * @param string $page
     *
     * @return array
     */
    protected function getListNews(string $page): array
    {
        $records = [];

        $crawler = new Crawler($page);
        $list = $crawler->filterXPath("//div[@class='td_block_inner td-column-2']")->filterXPath("//*[@class='entry-title td-module-title']/a");

        foreach ($list as $item) {
            $records[] = $item->getAttribute("href");
        }

        return $records;
    }

    /**
     *
     * @param string $uri
     *
     * @return string
     * @throws RuntimeException|\Exception
     */
    private function getPageContent(string $uri): string
    {
        $curl = Helper::getCurl();

        $result = $curl->get($uri);
        $responseInfo = $curl->getInfo();

        $httpCode = $responseInfo['http_code'] ?? null;

        if ($httpCode >= 200 && $httpCode < 400) {
            return $result;
        }

        throw new RuntimeException("Не удалось скачать страницу {$uri}, код ответа {$httpCode}");
    }

    /**
     *
     * @param string $text
     * @param array $search
     *
     * @return string
     */
    protected function clearText(string $text, array $search = []): string
    {
        $text = html_entity_decode($text);
        $text = strip_tags($text);
        $text = htmlentities($text);
        $search = array_merge(["&nbsp;"], $search);
        $text = str_replace($search, ' ', $text);
        $text = html_entity_decode($text);
        return trim($text);
    }

}