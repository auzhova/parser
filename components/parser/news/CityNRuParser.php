<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей с сайта https://www.city-n.ru/
 */
class CityNRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'https://www.city-n.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/div/1.html';
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $url = self::SITE_URL . $newsUrl;
            $contentPage = $this->getPageContent($url);
            if (!$contentPage) {
                continue;
            }

            $itemCrawler = new Crawler($contentPage);
            $content = $itemCrawler->filterXPath('//div[@class="main_content"]');
            $title = $content->filterXPath("//h1[@itemprop='headline']")->text();
            $date = $this->getDate($content->filterXPath("//div[@class='news_time_date']")->text());
            $image = null;
            $imgSrc = $content->filterXPath('//img');
            if ($imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            }
            $description = $itemCrawler->filterXPath("//div[@itemprop='description']")->text();

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $newContentCrawler = $itemCrawler->filterXPath("//div[@class='news_text']");
            foreach ($newContentCrawler as $contentNew) {
                foreach ($contentNew->childNodes as $childNode) {
                    if ($childNode->childNodes->count()) {
                        foreach ($childNode->childNodes as $childNodeItem) {
                            $this->setItemPostValue($post, $childNodeItem);
                        }
                    }else {
                        $this->setItemPostValue($post, $childNode);
                    }
                }
            }

            $video = $itemCrawler->filterXPath('//div[@class="youtube_button"]');
            $videoContent = $itemCrawler->filterXPath('//div[@class="news_media_content"]')->filterXPath("//a");
            if ($video->getNode(0) && $videoContent->getNode(0)) {
                foreach ($videoContent as $node) {
                    $src = $this->getHeadUrl($node->getAttribute('href'));
                    if ($videoContent && strpos($src, 'youtube') !== false || strpos($src, 'youtu.be') !== false) {
                        parse_str(parse_url($src, PHP_URL_QUERY ), $query);
                        if (isset($query['v'])) {
                            $youId = $query['v'];
                        } else {
                            $youId = basename(parse_url($src, PHP_URL_PATH));
                        }
                        $titleYou = $node->getAttribute('title');

                        $this->addItemPost($post, NewsPostItem::TYPE_VIDEO, $titleYou, null, null, null, $youId);

                    }
                }

            }

            $photo = $itemCrawler->filterXPath("//div[@class='news_photogallery']/a");
            foreach ($photo as $node) {
                $imgSrc = $this->getHeadUrl($node->getAttribute('href'));
                if ($imgSrc != $post->image && getimagesize($imgSrc)) {
                    foreach ($post->items as $item) {
                        if ($item->type == 2 && $item->image != $imgSrc) {
                            $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, $node->getAttribute('title'), $imgSrc);
                        }
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
     * @param NewsPost $post
     * @param DOMNode $node
     */
    protected function setItemPostValue (NewsPost $post, DOMNode $node): void {
        $nodeValue = $this->clearText($node->nodeValue, [$post->description]);
        if (in_array($node->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) && $nodeValue) {

            $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int)substr($node->nodeName, 1));

        } elseif ($node->nodeName == 'a' && strpos($href = $this->getHeadUrl($node->getAttribute('href')), 'http') !== false) {

            $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $href);

        } elseif ($node->nodeName == 'img' && ($imgSrc = $this->getHeadUrl($node->getAttribute('src'))) != $post->image && getimagesize($imgSrc)) {

            $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, $node->getAttribute('title'), $imgSrc);

        } elseif ($node->nodeName == 'iframe') {
            $src = $this->getHeadUrl($node->getAttribute('src'));
            if (strpos($src, 'youtube') !== false || strpos($src, 'youtu.be') !== false) {
                parse_str(parse_url($src, PHP_URL_QUERY ), $query);
                if (isset($query['v'])) {
                    $youId = $query['v'];
                } else {
                    $youId = basename(parse_url($src, PHP_URL_PATH));
                }
                $titleYou = $node->getAttribute('title');

                $this->addItemPost($post, NewsPostItem::TYPE_VIDEO, $titleYou, null, null, null, $youId);

            }

        } elseif ($node->childNodes->count()) {

            foreach ($node->childNodes as $childNode) {

                $this->setItemPostValue($post, $childNode);
            }

        } elseif ($nodeValue && $node->parentNode->attributes && $node->parentNode->getAttribute('class') == 'quote') {

            $this->addItemPost($post, NewsPostItem::TYPE_QUOTE, $nodeValue);

        } elseif ($nodeValue && $nodeValue != $post->description) {

            $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $nodeValue);

        }
    }

    /**
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date = ''): string
    {
        $newDate = new DateTime($date);
        $newDate->setTimezone(new DateTimeZone("UTC"));
        return $newDate->format("Y-m-d H:i:s");
    }


    /**
     *
     * @param string $url
     * @param string $det
     *
     * @return string
     */
    protected function getHeadUrl($url, $det = ''): string
    {
        $url = strpos($url, 'http') === false || strpos($url, 'http') > 0
            ? self::SITE_URL . $det . $url
            : $url;
        return $this->encodeUrl($url);
    }

    /**
     * Русские буквы в ссылке
     *
     * @param string $url
     *
     * @return string
     */
    protected function encodeUrl(string $url): string
    {
        $partsUrl = parse_url($url);
        if (preg_match('/[А-Яа-яЁё]/iu', $partsUrl['host'])) {
            $host = idn_to_ascii($partsUrl['host']);
            $url = str_replace($partsUrl['host'], $host, $url);
        }
        if (preg_match('/[А-Яа-яЁё]/iu', $url)) {
            preg_match_all('/[А-Яа-яЁё]/iu', $url, $result);
            $search = [];
            $replace = [];
            foreach ($result as $item) {
                foreach ($item as $key=>$value) {
                    $search[$key] = $value;
                    $replace[$key] = urlencode($value);
                }
            }
            $url = str_replace($search, $replace, $url);
        }
        return $url;
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
        $list = $crawler->filterXPath("//div[@class='left_column']")->filterXPath("//p/a");
        foreach ($list as $item) {
            $href = $item->getAttribute("href");
            if (!in_array($href, $records)) {
                $records[] = $href;
            }
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