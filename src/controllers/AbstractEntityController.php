<?php
/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\App;
use Elabftw\Elabftw\Tools;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Interfaces\ControllerInterface;
use Elabftw\Models\AbstractEntity;
use Elabftw\Models\Database;
use Elabftw\Models\Revisions;
use Elabftw\Models\TeamGroups;
use Elabftw\Models\Templates;
use Elabftw\Services\Check;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * For experiments.php
 */
abstract class AbstractEntityController implements ControllerInterface
{
    /** @var App $App instance of App */
    protected $App;

    /** @var AbstractEntity $Entity instance of AbstractEntity */
    protected $Entity;

    /** @var array $categoryArr array of category (status or item type) */
    protected $categoryArr = array();

    /**
     * Constructor
     *
     * @param App $app
     * @param AbstractEntity $entity
     */
    public function __construct(App $app, AbstractEntity $entity)
    {
        $this->App = $app;
        $this->Entity = $entity;
    }

    /**
     * Get the Response object from the Request
     *
     * @return Response
     */
    public function getResponse(): Response
    {
        // VIEW
        if ($this->App->Request->query->get('mode') === 'view') {
            return $this->view();
        }

        // EDIT
        if ($this->App->Request->query->get('mode') === 'edit') {
            return $this->edit();
        }

        // CREATE
        if ($this->App->Request->query->has('create')) {
            $id = $this->Entity->create((int) $this->App->Request->query->get('tpl'));
            return new RedirectResponse('?mode=edit&id=' . (string) $id);
        }

        // UPDATE RATING
        if ($this->App->Request->request->has('rating') && $this->Entity instanceof Database) {
            $this->Entity->setId((int) $this->App->Request->request->get('id'));
            $this->Entity->updateRating((int) $this->App->Request->request->get('rating'));
            $Response = new JsonResponse();
            $Response->setData(array(
                'res' => true,
                'msg' => _('Saved'),
            ));
            return $Response;
        }

        // DEFAULT MODE IS SHOW
        return $this->show();
    }

    /**
     * Get the items
     *
     * @return array
     */
    abstract protected function getItemsArr(string $searchType): array;

    /**
     * Show mode (several items displayed). Default view.
     *
     * @return Response
     */
    protected function show(): Response
    {
        // if this variable is not empty the error message shown will be different if there are no results
        $searchType = '';
        $query = '';

        // VISIBILITY LIST
        $TeamGroups = new TeamGroups($this->Entity->Users);
        $visibilityArr = $TeamGroups->getVisibilityList();

        // CATEGORY FILTER
        if (Check::id((int) $this->App->Request->query->get('cat')) !== false) {
            $this->Entity->addFilter('categoryt.id', $this->App->Request->query->get('cat'));
            $searchType = 'category';
        }
        // TAG FILTER
        if (!empty($this->App->Request->query->get('tags')[0])) {
            // get all the ids with that tag
            $ids = $this->Entity->Tags->getIdFromTags($this->App->Request->query->get('tags'), (int) $this->App->Users->userData['team']);
            $idFilter = ' AND (';
            foreach ($ids as $id) {
                $idFilter .= 'entity.id = ' . $id . ' OR ';
            }
            $trimmedFilter = rtrim($idFilter, ' OR ') . ')';
            $this->Entity->idFilter = $trimmedFilter;
            $searchType = 'tag';
        }
        // QUERY FILTER
        if (!empty($this->App->Request->query->get('q'))) {
            $query = $this->App->Request->query->filter('q', null, FILTER_SANITIZE_STRING);
            $this->Entity->queryFilter = Tools::getSearchSql($query, 'and', '', $this->Entity->type);
            $searchType = 'query';
        }

        // RELATED FILTER
        if (Check::id((int) $this->App->Request->query->get('related')) !== false) {
            $searchType = 'related';
        }

        // ORDER
        $order = '';

        // load the pref from the user
        if (isset($this->Entity->Users->userData['orderby'])) {
            $order = $this->Entity->Users->userData['orderby'];
        }

        // now get pref from the filter-order-sort menu
        if ($this->App->Request->query->has('order') && !empty($this->App->Request->query->get('order'))) {
            $order = $this->App->Request->query->get('order');
        }

        if ($order === 'cat') {
            $this->Entity->order = 'categoryt.id';
        } elseif ($order === 'date' || $order === 'rating' || $order === 'title' || $order === 'id' || $order === 'lastchange') {
            $this->Entity->order = 'entity.' . $order;
        } elseif ($order === 'comment') {
            $this->Entity->order = 'commentst.recent_comment';
        } elseif ($order === 'user') {
            $this->Entity->order = 'entity.userid';
        }

        // SORT
        $sort = '';

        // load the pref from the user
        if (isset($this->Entity->Users->userData['sort'])) {
            $sort = $this->Entity->Users->userData['sort'];
        }

        // now get pref from the filter-order-sort menu
        if ($this->App->Request->query->has('sort') && !empty($this->App->Request->query->get('sort'))) {
            $sort = $this->App->Request->query->get('sort');
        }

        if ($sort === 'asc' || $sort === 'desc') {
            $this->Entity->sort = $sort;
        }

        // PAGINATION
        $limit = (int) ($this->App->Users->userData['limit_nb'] ?? 15);
        if ($this->App->Request->query->has('limit')) {
            $limit = Check::limit((int) $this->App->Request->query->get('limit'));
        }

        $offset = 0;
        if ($this->App->Request->query->has('offset') && Check::id((int) $this->App->Request->query->get('offset')) !== false) {
            $offset = (int) $this->App->Request->query->get('offset');
        }

        $this->Entity->setOffset($offset);
        $this->Entity->setLimit($limit);
        // END PAGINATION


        // only show public to anon
        if ($this->App->Session->get('anon')) {
            $this->Entity->addFilter('entity.canread', 'public');
        }

        $itemsArr = $this->getItemsArr($searchType);
        // get tags separately
        $tagsArr = array();
        if (!empty($itemsArr)) {
            $tagsArr = $this->Entity->getTags($itemsArr);
        }

        // store the query parameters in the Session
        $this->App->Session->set('lastquery', $this->App->Request->query->all());

        $Templates = new Templates($this->Entity->Users);
        $templatesArr = $Templates->readAll();

        $template = 'show.html';

        $renderArr = array(
            'Entity' => $this->Entity,
            'categoryArr' => $this->categoryArr,
            'itemsArr' => $itemsArr,
            'limit' => $limit,
            'offset' => $offset,
            'query' => $query,
            'searchType' => $searchType,
            'tagsArr' => $tagsArr,
            'templatesArr' => $templatesArr,
            'visibilityArr' => $visibilityArr,
        );
        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render($template, $renderArr));

        return $Response;
    }

    /**
     * View mode (one item displayed)
     *
     * @return Response
     */
    protected function view(): Response
    {
        $this->Entity->setId((int) $this->App->Request->query->get('id'));
        $this->Entity->canOrExplode('read');

        // LINKS
        $linksArr = $this->Entity->Links->readAll();

        // STEPS
        $stepsArr = $this->Entity->Steps->readAll();

        // REVISIONS
        $Revisions = new Revisions($this->Entity);
        $revNum = $Revisions->readCount();

        // COMMENTS
        $commentsArr = $this->Entity->Comments->readAll();

        $timestampInfo = $this->Entity->getTimestampInfo();

        $template = 'view.html';
        // the mode parameter is for the uploads tpl
        $renderArr = array(
            'Entity' => $this->Entity,
            'commentsArr' => $commentsArr,
            'linksArr' => $linksArr,
            'mode' => 'view',
            'revNum' => $revNum,
            'stepsArr' => $stepsArr,
            'timestampInfo' => $timestampInfo,
        );

        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render($template, $renderArr));

        return $Response;
    }

    /**
     * Edit mode
     *
     * @return Response
     */
    protected function edit(): Response
    {
        $this->Entity->setId((int) $this->App->Request->query->get('id'));
        // check permissions
        $this->Entity->canOrExplode('write');
        // a locked entity cannot be edited
        if ($this->Entity->entityData['locked']) {
            throw new ImproperActionException(_('This item is locked. You cannot edit it!'));
        }

        // REVISIONS
        $Revisions = new Revisions($this->Entity);
        $revNum = $Revisions->readCount();

        // VISIBILITY ARR
        $TeamGroups = new TeamGroups($this->Entity->Users);
        $visibilityArr = $TeamGroups->getVisibilityList();

        // LINKS
        $linksArr = $this->Entity->Links->readAll();

        // STEPS
        $stepsArr = $this->Entity->Steps->readAll();

        $template = 'edit.html';

        $renderArr = array(
            'Entity' => $this->Entity,
            'categoryArr' => $this->categoryArr,
            'lang' => Tools::getCalendarLang($this->App->Users->userData['lang'] ?? 'en_GB'),
            'linksArr' => $linksArr,
            'maxUploadSize' => Tools::getMaxUploadSize(),
            'mode' => 'edit',
            'revNum' => $revNum,
            'stepsArr' => $stepsArr,
            'visibilityArr' => $visibilityArr,
        );

        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render($template, $renderArr));
        return $Response;
    }
}
