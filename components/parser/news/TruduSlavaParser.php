<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use yii\helpers\ArrayHelper;

/**
 * Парсер новостей с сайта http://www.trudu-slava.ru/
 */
class TruduSlavaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://www.trudu-slava.ru/';

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
        $newsList = $this->getListNews($newsPage, $urlNews);
        foreach ($newsList as $newsUrl) {
            $url = self::SITE_URL . $newsUrl;
            $contentPage = $this->getPageContent($url);
            if (!$contentPage) {
                continue;
            }
            $itemCrawler = new Crawler(null, $url);
            $itemCrawler->addHtmlContent($contentPage, 'UTF-8');

            $title = $itemCrawler->filterXPath("//*[@class='contentpagetitle']")->text();
            $date = $this->getDate($itemCrawler->filterXPath("//*[@class='createdate']")->text());
            $image = $this->getHeadUrl($itemCrawler->filterXPath('//p[3]/img')->attr('src'));
            $p = 3;

            $description = $itemCrawler->filterXPath('//p[3]');
            dd($itemCrawler->filterXPath("//*[@class='blog_more']")->parents());//->previousSibling);//->text();
            dd($itemCrawler->filterXPath('//p[3]')->text(),$itemCrawler->filterXPath('//p[10]')->text());
            foreach ($description as $key => $item) {
                foreach ($item->childNodes as $childNode) {
                    if($childNode->nodeName == '#text' && !$childNode->nodeValue) {
                        dd($childNode);
                    }
                    dump($childNode->nodeValue);
                }
                dd($item->childNodes);
                dump($item->nodeValue);
            }//*[@id="page"]/p[11]
            dd($description);
            dd($url,$itemCrawler->filterXPath('//*[@id="page"]/p[3]/img'));
            //
            dd($image);
//*[@id="page"]/p[3]
            $description = $itemCrawler->filterXPath("//*[@class='articletext']")->text();

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $title, null, null, 1);

            $newContentCrawler = (new Crawler($itemCrawler->filterXPath("//*[@class='articletext']")->html()))->filterXPath('//body')->children();

            foreach ($newContentCrawler as $content) {
                foreach ($content->childNodes as $childNode) {
                    $nodeValue = trim($childNode->nodeValue);
                    if (in_array($childNode->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {

                        $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int) substr($childNode->nodeName, 1));

                    } if ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false) {

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
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date): string
    {
        $str = explode(' ', $date);
        return ArrayHelper::getValue($str, 1, '');
    }

    /**
     * Получение списка ссылок на страницы новостей
     *
     * @param string $page
     *
     * @return array
     */
    protected function getListNews(string $page, string $link): array
    {
        $records = [];

        $crawler = new Crawler(null, $link);
        $crawler->addHtmlContent($page, 'UTF-8');
        $list = $crawler->filterXPath('//div[@class="leading"]/h2/a');
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

}