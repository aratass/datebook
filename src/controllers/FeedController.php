<?php

namespace zemis\datebook\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use zemis\datebook\Datebook;

/**
 * The private calendar feed and its link.
 */
class FeedController extends Controller
{
    protected array|bool|int $allowAnonymous = ['ics' => self::ALLOW_ANONYMOUS_LIVE];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if ($action->id !== 'ics') {
            $this->requireCpRequest();
            $this->requirePostRequest();
            $this->requireAcceptsJson();
            $this->requirePermission('accessPlugin-datebook');
            if (!Datebook::getInstance()->feeds->canSubscribe($this->user())) {
                throw new ForbiddenHttpException(Craft::t('datebook', 'You are not allowed to use calendar feeds.'));
            }
        }

        return true;
    }

    /**
     * Serves the ICS feed for a token. Optional query params: `site` (handle)
     * and `sections` (comma separated handles).
     */
    public function actionIcs(string $token): Response
    {
        $plugin = Datebook::getInstance();
        $user = $plugin->feeds->findUserByToken($token);
        if (!$user) {
            throw new NotFoundHttpException('Calendar feed not found.');
        }

        $siteHandle = $this->request->getQueryParam('site');
        $site = $plugin->calendar->resolveSite($user, is_string($siteHandle) ? $siteHandle : null);
        if (!$site) {
            throw new NotFoundHttpException('Calendar feed not found.');
        }

        $sectionIds = null;
        $handles = $this->request->getQueryParam('sections');
        if (is_string($handles) && $handles !== '') {
            $sectionIds = [];
            foreach (explode(',', $handles) as $handle) {
                $section = Craft::$app->getEntries()->getSectionByHandle(trim($handle));
                if ($section) {
                    $sectionIds[] = (int)$section->id;
                }
            }
        }

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()
            ->set('Content-Type', 'text/calendar; charset=utf-8')
            ->set('Content-Disposition', 'inline; filename="datebook.ics"')
            ->set('Cache-Control', 'private, no-store')
            ->set('X-Robots-Tag', 'noindex, nofollow');
        $response->content = $plugin->feeds->render($user, $site, $sectionIds);

        return $response;
    }

    /**
     * Returns the user's feed URL, creating it if needed.
     */
    public function actionUrl(): Response
    {
        $url = Datebook::getInstance()->feeds->getFeedUrl($this->user(), true);

        return $this->asJson(['url' => $url]);
    }

    /**
     * Creates a new feed URL. The old one stops working.
     */
    public function actionReset(): Response
    {
        $feeds = Datebook::getInstance()->feeds;
        $token = $feeds->resetToken($this->user());

        return $this->asSuccess(Craft::t('datebook', 'Your calendar link was reset. The old link no longer works.'), [
            'url' => $feeds->urlForToken($token),
        ]);
    }

    /**
     * The logged in user. Access rules in beforeAction() make sure there is one.
     *
     * @throws ForbiddenHttpException
     */
    private function user(): User
    {
        $user = static::currentUser();
        if (!$user instanceof User) {
            throw new ForbiddenHttpException();
        }

        return $user;
    }
}
