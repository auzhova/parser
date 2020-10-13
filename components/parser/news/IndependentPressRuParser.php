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
 * Парсер новостей с сайта https://independent-press.ru/
 */
class IndependentPressRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'https://independent-press.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/lastnews/';
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $url = $newsUrl;
            $contentPage = $this->getPageContent($url);
            $itemCrawler = new Crawler($contentPage);

            $title = $itemCrawler->filterXPath("//*[@class='story-full group']/h1")->text();
            $date = $itemCrawler->filterXPath("//*[@class='nav-story']/li")->text();
            $image = $this->getHeadUrl($itemCrawler->filterXPath("//*[@class='fullimgcenter']")->attr('src'));
            $description = $itemCrawler->filterXPath("//*[@class='full-str']")->text();

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $title, null, null, 1);

            $newContentCrawler = (new Crawler($itemCrawler->filterXPath("//*[@class='full-str']")->html()))->filterXPath('//body');

            foreach ($newContentCrawler as $content) {
                foreach ($content->childNodes as $childNode) {
                    $nodeValue = trim($childNode->nodeValue);

                    if ($nodeValue == 'Оставить комментарий') {
                        continue;
                    }

                    if ($nodeValue) {
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
        $str = explode('/', $date);
        $ruDate = ArrayHelper::getValue($str, 0, '');
        $ruMonths = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $enMonths = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
        return $ruDate ? str_ireplace($ruMonths, $enMonths, $ruDate) : '';
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
        $list = $crawler->filterXPath("//*[@class='left_cell']/a[1]");
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