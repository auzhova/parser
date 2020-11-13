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
 * Парсер новостей с сайта https://nversia.ru/
 */
class NversiaRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'https://nversia.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/news/';
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $url = self::SITE_URL . $newsUrl;
            $contentPage = $this->getPageContent($url);
            if (!$contentPage) {
                continue;
            }

            $itemCrawler = new Crawler($contentPage);
            $title = $itemCrawler->filterXPath("//h1[@class='post-title']")->text();
            $dateSrc = $itemCrawler->filterXPath("//div[@class='meta-item']/time");
            if ($dateSrc->getNode(0)) {
                $date = $this->getDate($dateSrc->attr('datetime'));
            } else {
                $date = $this->getDate();
            }

            $image = null;
            $imgSrc = $itemCrawler->filterXPath("//div[@class='post-cover__container']/img");
            if ($imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            }
            $description = $this->clearText($itemCrawler->filterXPath("//div[@class='article']")->filterXPath('//p[1]')->text());
            if (!$description) {
                $description = $this->clearText($itemCrawler->filterXPath("//div[@class='article-lead']")->filterXPath('//p[1]')->text());
            }

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $newContentCrawler = $itemCrawler->filterXPath("//div[@class='article']");
            foreach ($newContentCrawler as $contentNew) {
                foreach ($contentNew->childNodes as $childNode) {
                    $this->setItemPostValue($post, $childNode);
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

            if ($node->childNodes->count() > 1) {
                foreach ($node->childNodes as $childNode) {
                    if ($childNode->nodeName != 'img') {
                        continue;
                    }
                    $imgSrc = $this->getHeadUrl($childNode->getAttribute('src'));
                    if (!$post->image) {
                        $post->image = $imgSrc;
                    } else {
                        $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, $node->getAttribute('title'), $imgSrc);
                    }
                }
            } else {
                $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $href);
            }

        } elseif ($node->nodeName == 'img' && ($imgSrc = $this->getHeadUrl($node->getAttribute('data-lazy-src'))) != $post->image && getimagesize($imgSrc)) {

            $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, $node->getAttribute('title'), $imgSrc);

        } elseif ($node->nodeName == 'iframe') {
            $src = $this->getHeadUrl($node->getAttribute('src'));
            if (strpos($src, 'youtube') !== false) {
                $youId = basename(parse_url($src, PHP_URL_PATH));
                $titleYou = $node->getAttribute('title');

                $this->addItemPost($post, NewsPostItem::TYPE_VIDEO, $titleYou, null, null, null, $youId);

            }
        } elseif ($nodeValue && $node->parentNode->nodeName == 'blockquote') {

            $this->addItemPost($post, NewsPostItem::TYPE_QUOTE, $nodeValue);

        } elseif ($node->childNodes->count()) {

            foreach ($node->childNodes as $childNode) {

                $this->setItemPostValue($post, $childNode);
            }

        }  elseif ($nodeValue && $nodeValue != $post->description && mb_strpos($post->description, $nodeValue) === false) {

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
        $url = trim(urldecode($url));
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
        $list = $crawler->filterXPath("//div[@class='items']")->filterXPath("//a[@class='material-title material-title--undefined']");
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
        if ($text == '.') {
            return '';
        }
        $text = html_entity_decode($text);
        $text = strip_tags($text);
        $text = htmlentities($text);
        $search = array_merge(["&nbsp;"], $search);
        $text = str_replace($search, ' ', $text);
        $text = html_entity_decode($text);
        if (($point = mb_stripos($text, '.')) !== false && $point == 0) {
            $text = mb_substr($text, $point+1);
        }
        return trim($text);
    }
}