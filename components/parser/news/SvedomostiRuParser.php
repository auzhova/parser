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
 * Парсер новостей с сайта http://s-vedomosti.ru/
 */
class SvedomostiRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://s-vedomosti.ru';

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
            $content = $itemCrawler->filterXPath("//main[@id='main']");
            $title = $content->filterXPath("//h4[@class='entry-title']/a")->text();
            $date = $this->getDate($content->filterXPath("//span[@class='image-date img-post-date']")->text());
            $image = null;
            $imgSrc = $content->filterXPath("//img");
            if ($imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            }

            $description = $title;

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $description = '';
            $descriptionSrc = $content->filterXPath('//div[@class="entry-content"]')->children();
            foreach ($descriptionSrc as $key => $item) {
                foreach ($item->childNodes as $value) {
                    if (!$description && ($text = $this->clearText($value->nodeValue)) && $text != $title) {
                        $description = $text;
                    }
                }
            }
            if ($description) {
                $post->description = $description;
            }

            $newContentCrawler = $content->filterXPath('//div[@class="entry-content"]')->children();
            foreach ($newContentCrawler as $content) {
                if ($content->nodeName == 'figure') {
                    continue;
                }

                foreach ($content->childNodes as $key => $childNode) {
                    $nodeValue = $this->clearText($childNode->nodeValue, [$post->description]);
                    if (in_array($childNode->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) && $childNode->nodeValue != $title) {

                        $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int) substr($childNode->nodeName, 1));

                    } elseif ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false) {
                        $img = $childNode->firstChild;
                        if ($img && $img->nodeName == 'img' && $this->getHeadUrl($img->getAttribute('src')) != $image) {

                            $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $this->getHeadUrl($img->getAttribute('src')));

                        } else {

                            $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $childNode->getAttribute('href'));

                        }

                    } elseif ($childNode->nodeName == 'img' && $this->getHeadUrl($childNode->getAttribute('src')) != $image) {

                        $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $this->getHeadUrl($childNode->getAttribute('src')));

                    } elseif ($nodeValue && $nodeValue != $post->description) {

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
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date): string
    {
        $date = mb_strtolower($date);
        $day = '';
        $month = '';
        for ($i=0; $i<strlen($date); $i++) {
            is_numeric($date[$i]) ? $day .= $date[$i] : $month .= $date[$i];
        }
        $time = (new DateTime())->format("H:i:s");
        $date = $time . ' ' . $day . ' ' . $month;
        $ruMonths = ['янв', 'фев', 'мар', 'апр', 'мая', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
        $enMonths = ['january', 'february', 'march', 'april', 'may', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
        $newDate = new DateTime(str_ireplace($ruMonths, $enMonths, $date));
        $newDate->setTimezone(new DateTimeZone("UTC"));
        return $newDate->format("Y-m-d H:i:s");
    }


    /**
     *
     * @param string $url
     *
     * @return string
     */
    protected function getHeadUrl($url): string
    {
        $url = strpos($url, 'http') === false
            ? self::SITE_URL . $url
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
        $list = $crawler->filterXPath("//div[@class='container']")->filterXPath('//a[@class="banner-href"]');
        foreach ($list as $item) {
            $records[] = $item->getAttribute("href");
        }

        $list = $crawler->filterXPath("//div[@id='primary']")->filterXPath('//a[@class="readmore"]');
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
        $search = array_merge(["&nbsp;", "Share this:"], $search);
        $text = str_replace($search, ' ', $text);
        $text = html_entity_decode($text);
        return trim($text);
    }
}