<?php
/**
 * @link      https://craftcampaign.com
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\jobs;

use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\events\SendoutEvent;
use putyourlightson\campaign\services\SendoutsService;

use Craft;
use craft\helpers\App;
use craft\queue\BaseJob;

use Performance\Performance;

/**
 * SendoutJob
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.0.0
 */
class SendoutJob extends BaseJob
{
    // Properties
    // =========================================================================

    /**
     * @var int
     */
    public $sendoutId;

    /**
     * @var string|null
     */
    public $title;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws \Exception
     */
    public function execute($queue)
    {
        Performance::point();

        // Get sendout
        $sendout = Campaign::$plugin->sendouts->getSendoutById($this->sendoutId);

        // Fire a 'beforeSend' event
        $event = new SendoutEvent([
            'sendout' => $sendout,
        ]);
        Campaign::$plugin->sendouts->trigger(SendoutsService::EVENT_BEFORE_SEND, $event);

        if (!$event->isValid) {
            return;
        }

        // Call for max power
        App::maxPowerCaptain();
        //@set_time_limit(3);

        // Prepare sending
        Campaign::$plugin->sendouts->prepareSending($sendout);

        // Loop as long as the there are pending recipient IDs and the sendout is sendable
        $i = 0;
        $max = 100;
        while ($sendout->pendingRecipientIds AND $sendout->isSendable() AND $i < $max) {
            // Set progress
            //$progress = $sendout->getProgressFraction();
            $progress = $i / $max;
            $this->setProgress($queue, $progress);

            // Send email
            $sendout = Campaign::$plugin->sendouts->sendEmail($sendout);

            $i++;
        }

        // Finalise sending
        Campaign::$plugin->sendouts->finaliseSending($sendout);

        // Fire an 'afterSend' event
        if (Campaign::$plugin->sendouts->hasEventHandlers(SendoutsService::EVENT_AFTER_SEND)) {
            Campaign::$plugin->sendouts->trigger(SendoutsService::EVENT_AFTER_SEND, new SendoutEvent([
                'sendout' => $sendout,
            ]));
        }

        $export = Performance::export(); // Only return export

        // Return all information
        print_r($export->toFile('performance.txt'));
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('campaign', 'Sending “{title}” sendout', [
            'title' => $this->title,
        ]);
    }
}