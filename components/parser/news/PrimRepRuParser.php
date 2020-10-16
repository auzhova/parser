<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей с сайта http://primrep.ru/
 */
class PrimRepRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://primrep.ru';

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
            if (!$contentPage) {
                continue;
            }
            $itemCrawler = new Crawler($contentPage);

            $title = $itemCrawler->filterXPath("//h1[@class='entry-title']")->text();
            $date = $itemCrawler->filterXPath("//time[@class='entry-date updated']")->text();
            $image = $this->getHeadUrl($itemCrawler->filterXPath("//div[@class='entry-content']//*//img")->attr('src'));
            $description = $this->getDescription($itemCrawler->filterXPath("//div[@class='entry-content']")->text());

            if (!trim($description)) {
                $description = $title;
            }

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $title, null, null, 1);

            $newContentCrawler = (new Crawler($itemCrawler->filterXPath("//div[@class='entry-content']")->html()))->filterXPath('//body')->children();
            foreach ($newContentCrawler as $content) {
                foreach ($content->childNodes as $childNode) {
                    $nodeValue = $this->clearText($childNode->nodeValue);
                    if (strpos($nodeValue, 'Поделиться в соц. сетях') !== false) {
                        continue;
                    }
                    if (in_array($childNode->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {

                        $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int) substr($childNode->nodeName, 1));

                    } elseif ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false) {

                        $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $childNode->getAttribute('href'));

                    } elseif ($childNode->nodeName == 'img') {
                        $src = $childNode->getAttribute('src');
                        if ($src === $image) {
                            continue;
                        }

                        $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $src);

                    }
                    elseif ($childNode->nodeName != 'div' && $childNode->nodeName != '#comment' && $nodeValue) {

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
        $list = $crawler->filterXPath("//h2[@class='entry-title']/a");
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
        $curl->setOption(CURLOPT_COOKIE, 'beget=begetok');

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
     * @param string $text
     *
     * @return string
     */
    protected function getDescription($text): string
    {
        if (($f = strpos($text, 'Поделиться в соц. сетях')) !== false ) {
            $text = substr($text, 0, $f);
        }
        return $text;
    }
}