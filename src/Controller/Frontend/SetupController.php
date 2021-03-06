<?php
namespace App\Controller\Frontend;

use App\Acl;
use App\Auth;
use App\Entity;
use App\Exception\NotLoggedInException;
use App\Form\SettingsForm;
use App\Form\StationForm;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Settings;
use Azura\Session\Flash;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;

class SetupController
{
    /** @var EntityManager */
    protected $em;

    /** @var Entity\Repository\SettingsRepository */
    protected $settingsRepo;

    /** @var Auth */
    protected $auth;

    /** @var Acl */
    protected $acl;

    /** @var Settings */
    protected $settings;

    public function __construct(
        EntityManager $em,
        Entity\Repository\SettingsRepository $settingsRepository,
        Auth $auth,
        Acl $acl,
        Settings $settings
    ) {
        $this->em = $em;
        $this->settingsRepo = $settingsRepository;
        $this->auth = $auth;
        $this->acl = $acl;
        $this->settings = $settings;
    }

    /**
     * Setup Routing Controls
     *
     * @param ServerRequest $request
     * @param Response $response
     *
     * @return ResponseInterface
     */
    public function indexAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $current_step = $this->_getSetupStep();
        return $response->withRedirect($request->getRouter()->named('setup:' . $current_step));
    }

    /**
     * Determine which step of setup is currently active.
     *
     * @return string
     * @throws NotLoggedInException
     */
    protected function _getSetupStep(): string
    {
        if (0 !== (int)$this->settingsRepo->getSetting(Entity\Settings::SETUP_COMPLETE, 0)) {
            return 'complete';
        }

        // Step 1: Register
        $num_users = (int)$this->em->createQuery(/** @lang DQL */ 'SELECT COUNT(u.id) FROM App\Entity\User u')->getSingleScalarResult();
        if (0 === $num_users) {
            return 'register';
        }

        // If past "register" step, require login.
        if (!$this->auth->isLoggedIn()) {
            throw new NotLoggedInException;
        }

        // Step 2: Set up Station
        $num_stations = (int)$this->em->createQuery(/** @lang DQL */ 'SELECT COUNT(s.id) FROM App\Entity\Station s')->getSingleScalarResult();
        if (0 === $num_stations) {
            return 'station';
        }

        // Step 3: System Settings
        return 'settings';
    }

    /**
     * Placeholder function for "setup complete" redirection.
     *
     * @param ServerRequest $request
     * @param Response $response
     *
     * @return ResponseInterface
     */
    public function completeAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $request->getFlash()->addMessage('<b>' . __('Setup has already been completed!') . '</b>', Flash::ERROR);

        return $response->withRedirect($request->getRouter()->named('dashboard'));
    }

    /**
     * Setup Step 1:
     * Create Super Administrator Account
     *
     * @param ServerRequest $request
     * @param Response $response
     *
     * @return ResponseInterface
     */
    public function registerAction(ServerRequest $request, Response $response): ResponseInterface
    {
        // Verify current step.
        $current_step = $this->_getSetupStep();
        if ($current_step !== 'register' && $this->settings->isProduction()) {
            return $response->withRedirect($request->getRouter()->named('setup:' . $current_step));
        }

        // Create first account form.
        $data = $request->getParams();

        if (!empty($data['username']) && !empty($data['password'])) {
            // Create actions and roles supporting Super Admninistrator.
            $role = new Entity\Role;
            $role->setName(__('Super Administrator'));

            $this->em->persist($role);
            $this->em->flush();

            $rha = new Entity\RolePermission($role);
            $rha->setActionName('administer all');

            $this->em->persist($rha);

            // Create user account.
            $user = new Entity\User;
            $user->setEmail($data['username']);
            $user->setNewPassword($data['password']);
            $user->getRoles()->add($role);
            $this->em->persist($user);

            // Write to DB.
            $this->em->flush();

            // Log in the newly created user.
            $this->auth->authenticate($data['username'], $data['password']);
            $this->acl->reload();

            return $response->withRedirect($request->getRouter()->named('setup:index'));
        }

        return $request->getView()
            ->renderToResponse($response, 'frontend/setup/register');
    }

    /**
     * Setup Step 2:
     * Create Station and Parse Metadata
     *
     * @param ServerRequest $request
     * @param Response $response
     *
     * @param StationForm $stationForm
     *
     * @return ResponseInterface
     */
    public function stationAction(
        ServerRequest $request,
        Response $response,
        StationForm $stationForm
    ): ResponseInterface {
        // Verify current step.
        $current_step = $this->_getSetupStep();
        if ($current_step !== 'station' && $this->settings->isProduction()) {
            return $response->withRedirect($request->getRouter()->named('setup:' . $current_step));
        }

        if (false !== $stationForm->process($request)) {
            return $response->withRedirect($request->getRouter()->named('setup:settings'));
        }

        return $request->getView()->renderToResponse($response, 'frontend/setup/station', [
            'form' => $stationForm,
        ]);
    }

    /**
     * Setup Step 3:
     * Set site settings.
     *
     * @param ServerRequest $request
     * @param Response $response
     *
     * @param SettingsForm $settingsForm
     *
     * @return ResponseInterface
     */
    public function settingsAction(
        ServerRequest $request,
        Response $response,
        SettingsForm $settingsForm
    ): ResponseInterface {
        // Verify current step.
        $current_step = $this->_getSetupStep();
        if ($current_step !== 'settings' && $this->settings->isProduction()) {
            return $response->withRedirect($request->getRouter()->named('setup:' . $current_step));
        }

        if ($settingsForm->process($request)) {
            $this->settingsRepo->setSetting(Entity\Settings::SETUP_COMPLETE, time());

            // Notify the user and redirect to homepage.
            $request->getFlash()->addMessage('<b>' . __('Setup is now complete!') . '</b><br>' . __('Continue setting up your station in the main AzuraCast app.'),
                Flash::SUCCESS);

            return $response->withRedirect($request->getRouter()->named('dashboard'));
        }

        return $request->getView()->renderToResponse($response, 'frontend/setup/settings', [
            'form' => $settingsForm,
        ]);
    }
}
