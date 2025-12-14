<?php

declare(strict_types=1);

namespace BbApp\PushService\BbPress;

use BbApp\PushService\WordPressBase\WordPressBasePushSource;
use WP_Post;
use UnexpectedValueException;

/**
 * bbPress-specific implementation of push notification source.
 */
class BbPressPushSource extends WordPressBasePushSource
{
	/**
	 * Extracts message data from bbPress topic or reply for push notifications.
	 */
    public function extract_message_data($object): array
    {
        if (
            !($object instanceof WP_Post) ||
            !in_array($object->post_type, ['topic', 'reply'], true)
        ) {
            throw new UnexpectedValueException();
        }

        if ($object->post_author > 0) {
            $user = get_user_by('id', $object->post_author);

            if ($user) {
                $username = $user->display_name;
            }
        } else {
            $username = get_post_meta($object->ID, '_bbp_anonymous_name', true);
        }

        if (!isset($username)) {
            $username = __('Anonymous', 'bb-app');
        }

        return compact('username') + [
            'id' => $object->ID,
            'user_id' => (int) $object->post_author,
            'title' => $object->post_title,
            'content' => $this->get_message_content($object->post_content),
            'post__title' => get_the_title(intval(get_post_meta($object->ID, '_bbp_topic_id', true))),
            'section__title' => get_the_title(intval(get_post_meta($object->ID, '_bbp_forum_id', true)))
        ];
    }

	/**
	 * Builds subscription targets for forum hierarchy and threaded replies.
	 */
    public function build_push_service_targets_for_object($object): array
    {
        $targets = [];

        if ($object->post_type === $this->content_source->get_entity_types('post')) {
            $targets[] = [$this->content_source->get_entity_types('section'), (int) $object->post_parent];
        } elseif ($object->post_type === $this->content_source->get_entity_types('comment')) {
            $reply_to = (int) get_post_meta($object->ID, '_bbp_reply_to', true);
            $threaded = (bool) get_option('_bbp_thread_replies', false);
            $max_depth = (int) get_option('_bbp_thread_replies_depth', 2);

            if ($threaded && $max_depth > 1 && $reply_to > 0) {
                $targets[] = [$this->content_source->get_entity_types('comment'), $reply_to];
            } else {
                $targets[] = [$this->content_source->get_entity_types('post'), (int) $object->post_parent];
            }
        }

        return $targets;
    }

	/**
	 * Handles topic and reply creation for push notifications.
	 */
    public function wp_insert_post($post_ID, $post, bool $updating): void
    {
        $post_types = [
            $this->content_source->get_entity_types('post'),
            $this->content_source->get_entity_types('comment')
        ];

        if (
            $updating ||
            !in_array($post->post_type, $post_types) ||
            wp_is_post_autosave($post) ||
            wp_is_post_revision($post) ||
            (function_exists('wp_doing_rest') && \wp_doing_rest())
        ) {
            return;
        }

        $this->handle_content_insertion($post);
    }

	/**
	 * Registers WordPress hooks for bbPress post insertion.
	 */
    public function register(): void
    {
        parent::register();

        add_action('wp_insert_post', [$this, 'wp_insert_post'], 10, 3);
    }

	/**
	 * Validates if bbPress content is eligible for notifications.
	 */
    protected function is_valid_content_for_notification($content): bool
    {
        if ($content instanceof WP_Post) {
            $post_types = [
                $this->content_source->get_entity_types('post'),
                $this->content_source->get_entity_types('comment')
            ];

            if (!in_array($content->post_type, $post_types)) {
                return false;
            }

            if ($content->post_status !== 'publish') {
                return false;
            }

            return true;
        }

        return false;
    }

	/**
	 * Gets the object type from bbPress post type.
	 */
    protected function get_object_type($content): string
    {
        if ($content instanceof WP_Post) {
            return $content->post_type;
        }

        return '';
    }
}
