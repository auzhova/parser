<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей с сайта http://присаянье.рф/
 */
class PrisayanieParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://xn--80akhvhgi4gza.xn--p1ai';

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

            $title = $itemCrawler->filterXPath("//header/a")->text();
            $date = $this->getDate($itemCrawler->filterXPath("//header/time")->text());
            $image = null;
            $imgSrc = $itemCrawler->filterXPath("//div/a/img");
            if ($imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            }
            $description = $itemCrawler->filterXPath("//article/p")->text();

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $video = $itemCrawler->filterXPath("//div[@id='MainMasterContentPlaceHolder_InsidePlaceHolder_divVideo']/iframe");
            if ($video->getNode(0) && $src = $video->attr('src')) {
                if (strpos($src, 'youtube') !== false) {
                    $youId = basename(parse_url($src, PHP_URL_PATH));

                    $this->addItemPost($post, NewsPostItem::TYPE_VIDEO, $video->attr('title'), null, null, null, $youId);

                }
            }

            $newContentCrawler = $itemCrawler->filterXPath("//div[@id='MainMasterContentPlaceHolder_InsidePlaceHolder_articleText']/p");

            foreach ($newContentCrawler as $content) {
                foreach ($content->childNodes as $childNode) {
                    $nodeValue = $this->clearText($childNode->nodeValue);
                    if ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false
                        && basename(parse_url($childNode->getAttribute('href'), PHP_URL_PATH)) != basename(parse_url($image, PHP_URL_PATH))
                    ) {

                        $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $childNode->getAttribute('href'));

                    } elseif ($nodeValue) {
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
        $list = $crawler->filterXPath("//div[@class='news-container']")->filterXPath("//h3/a");
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
     *
     * @return string
     */
    protected function clearText(string $text): string
    {
        $text = trim($text);
        $text = htmlentities($text);
        $text = str_replace("&nbsp;",'',$text);
        $text = html_entity_decode($text);
        return $text;
    }

    /**
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date): string
    {
        $newDate = new \DateTime($date);
        $newDate->setTimezone(new \DateTimeZone("UTC"));
        return $newDate->format("Y-m-d H:i:s");
    }

}