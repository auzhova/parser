<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей с сайта http://klinfm.ru/
 */
class KlinFmRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://klinfm.ru';

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
            $content = $itemCrawler->filterXPath("//div[@id='content']");
            $title = $content->filterXPath("//h1[@class='entry-title']");
            if ($title->getNode(0)) {
                $title = $title->text();
            } else {
                $title = $content->filterXPath("//div[@class='entry-content']/*/p[1]")->text();
            }

            $date = $this->getDate($content->filterXPath("//span[@class='date published time']")->attr('title'));
            $image = null;
            $imgSrc = $content->filterXPath("//img");
            if ($imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            }
            $description = $content->filterXPath('//div[@class="entry-content"]')->children()->text();
            $description = str_replace($title, '', $description);

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $newContentCrawler = $content->filterXPath('//div[@class="entry-content"]/st_index/p');
            foreach ($newContentCrawler as $content) {
                foreach ($content->childNodes as $key => $childNode) {
                    $nodeValue = $this->clearText($childNode->nodeValue);
                    if (in_array($childNode->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) && $childNode->nodeValue != $title) {

                        $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int) substr($childNode->nodeName, 1));

                    } elseif ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false) {
                        $img = $childNode->firstChild;
                        if ($img && $img->nodeName == 'img' && $img && $img->getAttribute('src') == $image) {
                            continue;
                        } elseif ($img->nodeName == 'img') {

                            $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $img->getAttribute('src'));

                        } else {

                            $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $childNode->getAttribute('href'));

                        }

                    } elseif ($childNode->nodeName == 'img' && $childNode->getAttribute('src') != $image) {

                        $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $childNode->getAttribute('src'));

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
        $list = $crawler->filterXPath("//div[@class='entry-content']")->filterXPath('//a[@class="more-link"]');
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
        $text = htmlentities($text);
        $text = str_replace("&nbsp;",' ',$text);
        $text = html_entity_decode($text);
        return trim($text);
    }
}