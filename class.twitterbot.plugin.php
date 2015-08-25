<?php defined('APPLICATION') or die;

$PluginInfo['TwitterBot'] = array(
    'Name' => 'Twitter Bot',
    'Description' => 'Twitters new discussions autmatically.',
    'Version' => '0.1',
    'RequiredApplications' => array('Vanilla' => '>= 2.1'),
    'RequiredTheme' => false,
    'SettingsPermission' => 'Garden.Settings.Manage',
    'SettingsUrl' => '/dashboard/settings/twitterbot',
    'MobileFriendly' => true,
    'HasLocale' => true,
    'Author' => 'Robin Jurinka',
    'AuthorUrl' => 'http://vanillaforums.org/profile/44046/R_J',
    'License' => 'MIT'
);

class TwitterBotPlugin extends Gdn_Plugin {
    /**
     * Adds a checkbox o new discussions.
     *
     * If not switched of in the settings, a checkbox is shown so that
     * individual discussions can explicetly _not_ be twittered.
     *
     * @param object $sender PostController.
     * @param array $args EventArguments containing the discussion options.
     * @return void.
     * @package TwitterBot
     * @since 0.1
     */
    public function postController_discussionFormOptions_handler($sender, $args) {
        // Exit if the current user hasn't the permission to twitter
        $roleIds = array_keys(Gdn::userModel()->getRoles(Gdn::session()->UserID));
        if (array_intersect($roles, Gdn::config('TwitterBot.RoleIDs'))) {
            return;
        }

        // Exit if checkbox is shown and not ticked
        if (!Gdn::config('TwitterBot.ShowCheckbox')) {
            return;
        }

        // Exit if the plugin has not been set up correctly
        $consumerKey = Gdn::config('TwitterBot.ConsumerKey');
        $secret = Gdn::config('TwitterBot.Secret');
        if (!$consumerKey || !$secret) {
            return;
        }

        $args['Options'] .= wrap(
            $sender->Form->checkBox(
                'TwitterBot',
                t('Publish on Twitter'),
                array('value' => '1', 'checked' => true)
            ),
            'li'
        );
    }

    /**
     * Do some validations before discussion is saved.
     *
     * @param object $sender DiscussionModel.
     * @param array $args EventArguments.
     * @return void.
     * @package TwitterBot
     * @since 0.1
     */
    public function discussionModel_beforeSaveDiscussion_handler($sender, $args) {
        // If "Publish on Twitter" is unchecked, no sanity checks must be done
        if (!$args['FormPostValues']['TwitterBot']) {
            return;
        }

        // Check if plugin is configured
        $consumerKey = Gdn::config('TwitterBot.ConsumerKey');
        $consumerSecret = Gdn::config('TwitterBot.ConsumerSecret');
        $oAuthAccessToken = Gdn::config('TwitterBot.OAuthAccessToken');
        $oAuthAccessTokenSecret = Gdn::config('TwitterBot.OAuthAccessTokenSecret');
        if (!$consumerKey || !$consumerSecret || !$oAuthAccessToken || !$oAuthAccessTokenSecret) {
            return;
        }

        // Check for role permissions
        $roleIds = array_keys(Gdn::userModel()->getRoles(Gdn::session()->UserID));
        if (array_intersect($roles, Gdn::config('TwitterBot.RoleIDs'))) {
            // Don't give feedback since this is only true on error or if
            // user has spoofed post data. Desired result is that discussion is
            // posted to forum without issues but not on Twitter.
            return;
        }

        // Check for allowed category
        $categoryID = $args['FormPostValues']['CategoryID'];
        if (!in_array($categoryID, Gdn::config('TwitterBot.CategoryIDs'))) {
            $sender->Validation->addValidationResult('CategoryID', 'Discussions in this category will not be published on Twitter. Please uncheck "Publish on Twitter" or choose a valid category.');
        }

        // Check for restriction to announcements
        if (Gdn::config('TwitterBot.AnnouncementsOnly') && !$args['FormPostValues']['Announce']) {
            $sender->Validation->addValidationResult('Publish on Twitter', 'Only Announcements will be published on Twitter. Either make this an Announcement or uncheck the "Publich on Twitter" checkbox.');
        }
    }

    /**
     * Check requirements and publish new discussion to Twitter.
     *
     * @param object $sender DiscussionModel.
     * @param array $args EventArguments.
     * @return void.
     * @package TwitterBot
     * @since 0.1
     */
    public function discussionModel_afterSaveDiscussion_handler($sender, $args) {
        $discussion = $args['Discussion'];

        // Exit if this discussion has already been twittered.
        if ($discussion->Attributes['TwitterBot'] == true) {
            return;
        }

        // Exit if discussions from this category shouldn't be twittered
        if (!in_array($discussion->CategoryID, Gdn::config('TwitterBot.CategoryIDs'))){
            return;
        }

        // Exit if the current user hasn't the permission to twitter
        $roleIds = array_keys(Gdn::userModel()->getRoles($discussion->InsertUserID));
        if (array_intersect($roles, Gdn::config('TwitterBot.RoleIDs'))){
            return;
        }

        // Exit if only announcements shall be twittered and this is no announcements
        if (Gdn::config('TwitterBot.AnnouncementsOnly') && !$discussion->Announce) {
            return;
        }

        // Exit if checkbox is shown and not ticked
        if (Gdn::config('TwitterBot.ShowCheckbox') && !$args['FormPostValues']['TwitterBot']) {
            return;
        }

        // Exit if plugin is not configured
        $consumerKey = Gdn::config('TwitterBot.ConsumerKey');
        $consumerSecret = Gdn::config('TwitterBot.ConsumerSecret');
        $oAuthAccessToken = Gdn::config('TwitterBot.OAuthAccessToken');
        $oAuthAccessTokenSecret = Gdn::config('TwitterBot.OAuthAccessTokenSecret');

        if (!$consumerKey || !$consumerSecret || !$oAuthAccessToken || !$oAuthAccessTokenSecret) {
            return;
        }

        $title = $discussion->Name;
        $body = Gdn_Format::to($discussion->Body, $discussion->Format);
        $author = $discussion->InsertName;
        $date = $discussion->DateInserted;
        $category = $discussion->Category;
        $url = $discussion->Url;

        $tweet = '"'.$title.'" by '.$author;

        require_once(__DIR__.'/library/vendors/twitter-api-php/TwitterAPIExchange.php');
        $settings = array(
            'oauth_access_token' => $oAuthAccessToken,
            'oauth_access_token_secret' => $oAuthAccessTokenSecret,
            'consumer_key' => $consumerKey,
            'consumer_secret' => $consumerSecret
        );

        $twitter = new TwitterAPIExchange($settings);
        $response = $twitter
            ->buildOauth('https://api.twitter.com/1.1/statuses/update.json', 'POST')
            ->setPostfields(array('status' => $tweet))
            ->performRequest();

        $response = json_decode($response, true);

        if (isset($response['created_at'])) {
            // Gdn::controller()->informMessage('This discussion has been published on Twitter', 'Dismissable');
            Gdn::discussionModel()->saveToSerializedColumn('Attributes', $discussion->DiscussionID, 'TwitterBot', true);
        }
    }

    /**
     * Create settings screen for plugin.
     *
     * @param $sender SettingsController.
     * @return void.
     * @package TwitterBot
     * @since 0.1
     */
    public function settingsController_twitterBot_create($sender) {
        $sender->permission('Garden.Settings.Manage');
        $sender->addSideMenu('dashboard/settings/plugins');

        $categories = CategoryModel::categories();
        unset($categories[-1]);
        $roleModel = new RoleModel();
        $userRoles = $roleModel->getArray();

        $configurationModule = new ConfigurationModule($sender);
        $configurationModule->initialize(array(
            'TwitterBot.ConsumerKey' => array(
                'LabelCode' => 'Your applications consumer key',
                'Options' => array('class' => 'InputBox BigInput')
            ),
            'TwitterBot.ConsumerSecret' => array(
                'LabelCode' => 'The secret for your consumer key',
                'Options' => array('class' => 'InputBox BigInput')
            ),
            'TwitterBot.OAuthAccessToken' => array(
                'LabelCode' => 'Your oAuth access token',
                'Options' => array('class' => 'InputBox BigInput')
            ),
            'TwitterBot.OAuthAccessTokenSecret' => array(
                'LabelCode' => 'The secret for your oAuth access token',
                'Options' => array('class' => 'InputBox BigInput')
            ),
            'TwitterBot.CategoryIDs' => array(
                'Control' => 'CheckBoxList',
                'LabelCode' => 'Categories',
                'Items' => $categories,
                'Description' => 'Tweet discussions from this categories',
                'Options' => array('ValueField' => 'CategoryID', 'TextField' => 'Name')
            ),
            'TwitterBot.RoleIDs' => array(
                'Control' => 'CheckBoxList',
                'LabelCode' => 'User roles',
                'Items' => $userRoles,
                'Description' => 'Only discussions of this user roles will be published on Twitter',
                'Options' => array('ValueField' => 'RoleID', 'TextField' => 'Name')
            ),
            'TwitterBot.AnnouncementsOnly' => array(
                'Control' => 'CheckBox',
                // 'Description' => 'By ticking this checkbox, only announcements will be tweeted',
                'LabelCode' => 'Only tweet announcements',
                'Default' => true
            ),
            'TwitterBot.ShowCheckbox' => array(
                'Control' => 'CheckBox',
                // 'Description' => 'Show checkbox below discussions so that users with correct permissions can choose not to tweet a discussion',
                'LabelCode' => 'Show checkbox below new discussions',
                'Default' => true
            )
        ));

        $sender->setData('Title', t('TwitterBot Settings'));
        $sender->setData('Description', t('New discussions will automatically appear on your Twitter account. You have to <a href="https://apps.twitter.com/app/new">create a new app</a> and fill the credentials in her to make it work.'));

        $configurationModule->renderAll();
    }
}
