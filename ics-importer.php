<?php
/*
Plugin Name: DSA ICS Importer
Description: A plugin for importing events from ICS files into WordPress.

IMPORTANT NOTES:

BE SURE YOU DEFINE HARD-CODED CATEGORY VALUES AND URLS STARTING ON LINE 47 BELOW!
ALSO BE SURE THAT YOU HAVE PAUSED THE MEC_SCHEDULER CRON TASK IN CRONTROL

This code is provided “as is” without warranty of any kind, expressed or implied.
Version: 1.0
Author: Shaun Geisert
*/

// Check if WordPress is loaded
if (!defined('ABSPATH')) {
    wp_die('Direct script access denied.');
}

define('DEBUG', false);

// Hook the function to check the URL and import events
add_action('ics_importer_cron_hook', 'ics_importer_cron_callback', 10, 2);

// Schedule the cron event
register_activation_hook(__FILE__, 'ics_importer_activate');
register_deactivation_hook(__FILE__, 'ics_importer_deactivate');

function ics_importer_deactivate()
{
    wp_clear_scheduled_hook('ics_importer_cron_hook');
}

// Schedule cron events for each category
function ics_importer_activate()
{
    // Delete all events upon plugin activation
    delete_all_events(false);

    // Disable MEC scheduler.  It deletes entries from mec_events that I've manually added.
    wp_clear_scheduled_hook('mec_scheduler');

    // Set up all categories/ics feeds
    $categories = [
        'Climbing Wall' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/climbingWall.ics',
        'Drop-in Sports' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/dropinSports.ics',
        'Red Cross Classes' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/redCross.ics',
        'Drop-in Swim' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/dropinSwim.ics',
        'Facility Closures' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/facilityClosures.ics',
        'Facility Hours' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/facilityHours.ics',
        'Group Classes' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/groupclasses.ics',
        'Out In The Rec' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/outintherec.ics',
        'Outdoor Programs' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/outdoorPrograms.ics',
        'Radical Self Love' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/selflove.ics',
    ];

    // if debug mode just handle a couple categories
    if (DEBUG) {
        $categories = [
            'Drop-in Swim' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/test.ics',
            'Climbing Wall' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/test2.ics',
        ];
    }

    // Schedule separate cron events for each category
    foreach ($categories as $categoryName => $categoryUrl) {
        if ($categoryName === 'Drop-in Swim') {
            // Schedule "Drop-in Swim" to run every hour
            wp_schedule_event(time(), 'hourly', 'ics_importer_cron_hook', [$categoryUrl, $categoryName]);
        } else {
            // Schedule other categories to run daily
            wp_schedule_event(time(), 'daily', 'ics_importer_cron_hook', [$categoryUrl, $categoryName]);
        }
    }
}

// Handle a specific category
function ics_importer_cron_callback($categoryUrl, $categoryName)
{
    // Disable MEC scheduler.  It deletes entries from mec_events that I've manually added.
    wp_clear_scheduled_hook('mec_scheduler');

    // Get all WordPress posts of the specified post type
    //$existing_posts = get_posts(['post_type' => 'mec-events', 'posts_per_page' => -1]);

    // Run the import for the specific category
    ics_importer_run($categoryUrl, $categoryName);
}

function ics_importer_run($icsUrl, $categoryName)
{
    // Parse ICS content and get events_data
    $ics_data = parse_ics_content(file_get_contents($icsUrl));
    
     // Fetch all MEC posts from WordPress
    $mec_posts = get_mec_events($categoryName);

    if (DEBUG) {
        //error_log('MEC Posts Count: ' . count($mec_posts));
    }

    $ics_keys = update_or_create_events($ics_data, $mec_posts, $categoryName);
    delete_nonexistent_events($mec_posts, $ics_keys);
}

function delete_nonexistent_events($mec_posts, $ics_keys)
{
    foreach ($mec_posts as $mec_post) {
        $mec_key = get_mec_signature($mec_post);

        if (!isset($ics_keys[$mec_key])) {
            wp_delete_post($mec_post->ID, true);
        }
    }
}

function update_or_create_events($ics_data, $mec_posts, $categoryName)
{
    $ics_keys = [];

    foreach ($ics_data as $ics_event) {
        $ics_key = get_ics_signature($ics_event);
        $event_exists = false;

        foreach ($mec_posts as $mec_post) {
            if ($ics_key === get_mec_signature($mec_post)) {
                $event_exists = true;
                break;
            }
        }

        if (!$event_exists) {
            create_event_post($ics_event, $categoryName);
        }

        $ics_keys[$ics_key] = true;
    }

    return $ics_keys;
}

function get_ics_signature($event)
{
    $icsTitleCleaned = strtolower(preg_replace('/[^A-Za-z0-9\-.,:_]/', '', $event['title']));
    $icsStartDateTime = new DateTime($event['start']);
    $icsEndDateTime = new DateTime($event['end']);

    //if (DEBUG) {
    //    $titleComparisonResult = strcmp($icsTitleCleaned, $postTitle);
    //    error_log('Title match! ' . $postTitle . ' | ' . $icsTitleCleaned);
    //    error_log('Start match! ' . $postStartDateTime->format('Y-m-d H:i:s') . ' | ' . $icsStartDateTime->format('Y-m-d H:i:s'));
    //    error_log('End match! ' . $postEndDateTime->format('Y-m-d H:i:s') . ' | ' . $icsEndDateTime->format('Y-m-d H:i:s'));
    //}

    return $icsTitleCleaned . $icsStartDateTime->format('Y-m-d H:i:s') . $icsEndDateTime->format('Y-m-d H:i:s');
}

function get_mec_signature($event)
{
    $postID = is_object($event) ? $event->ID : (is_array($event) ? $event['ID'] : null);
    $postTitle = is_object($event) ? $event->post_title : (is_array($event) ? $event['post_title'] : null);
    $postTitle = strtolower(preg_replace('/[^A-Za-z0-9\-.,:_]/', '', $postTitle));
    $postStartStr = get_post_meta($postID, 'mec_start_datetime', true);
    $postEndStr = get_post_meta($postID, 'mec_end_datetime', true);
    $postStartDateTime = new DateTime($postStartStr);
    $postEndDateTime = new DateTime($postEndStr);

    return $postTitle . $postStartDateTime->format('Y-m-d H:i:s') . $postEndDateTime->format('Y-m-d H:i:s');
}

function event_exists($ics_event, $mec_post)
{
    // Check if an event with the same title, start, and end date exists
    if (get_ics_signature($ics_event) == get_mec_signature($mec_post)) {
        $postID = $mec_post->ID;

        // Event exists, now also check if any detail is changed
        $title = get_the_title($postID);
        $start = get_post_meta($postID, 'mec_start_datetime', true);
        $end = get_post_meta($postID, 'mec_end_datetime', true);
        $time_start = get_post_meta($postID, 'mec_start_day_seconds', true);
        $time_end = get_post_meta($postID, 'mec_end_day_seconds', true);

        // Extract the time and convert it to seconds
        $ics_time_start = strtotime($ics_event['start']) - strtotime(gmdate('Y-m-d 00:00:00', strtotime($ics_event['start'])));
        $ics_time_end = strtotime($ics_event['end']) - strtotime(gmdate('Y-m-d 00:00:00', strtotime($ics_event['end'])));

        if ($title != $ics_event['title'] || $start != $ics_event['start'] || $end != $ics_event['end'] || $time_start != $ics_time_start || $time_end != $ics_time_end) {
            // If any detail is changed, then update the event in WordPress
            $event = [
                'ID' => $postID,
                'post_title' => $ics_event['title'],
                'post_content' => $ics_event['description'],
                'post_status' => 'publish',
                'post_type' => 'mec-events',
            ];

            // Update the event post
            wp_update_post($event);

            // Update the event custom fields
            update_post_meta($postID, 'mec_start_datetime', $ics_event['start']);
            update_post_meta($postID, 'mec_end_datetime', $ics_event['end']);
            update_post_meta($postID, 'mec_start_day_seconds', $ics_time_start);
            update_post_meta($postID, 'mec_end_day_seconds', $ics_time_end);
        }

        return true;
    }

    return false;
}

// can't use the following function because the Fusion folks are complete idiots and generate a new uid, even for the same event.  Darrrrr
function event_exists_uid($ics_event, $mec_post)
{
    $postID = is_object($mec_post) ? $mec_post->ID : (is_array($mec_post) ? $mec_post['ID'] : null);
    $postUID = get_post_meta($postID, 'mec_ics_uid', true);

    // Log the count of MEC posts
    if (DEBUG) {
        error_log('ics_event uid: ' . $ics_event['uid']);
        error_log('postUID: ' . $postUID);
    }

    // Compare the unique id of the ICS event and the current post
    if ($postUID == $ics_event['uid']) {
        // Event exists, now also check if any detail is changed
        $title = get_the_title($postID);
        $start = get_post_meta($postID, 'mec_start_datetime', true);
        $end = get_post_meta($postID, 'mec_end_datetime', true);
        $time_start = get_post_meta($postID, 'mec_start_day_seconds', true);
        $time_end = get_post_meta($postID, 'mec_end_day_seconds', true);

        // Extract the time and convert it to seconds
        $ics_time_start = strtotime($ics_event['start']) - strtotime(gmdate('Y-m-d 00:00:00', strtotime($ics_event['start'])));
        $ics_time_end = strtotime($ics_event['end']) - strtotime(gmdate('Y-m-d 00:00:00', strtotime($ics_event['end'])));

        if ($title != $ics_event['title'] || $start != $ics_event['start'] || $end != $ics_event['end'] || $time_start != $ics_time_start || $time_end != $ics_time_end) {
            // If any detail is changed, then update the event in WordPress
            $event = [
                'ID' => $postID,
                'post_title' => $ics_event['title'],
                'post_content' => $ics_event['description'],
                'post_status' => 'publish',
                'post_type' => 'mec-events',
            ];

            // Update the event post
            wp_update_post($event);

            // Update the event custom fields
            update_post_meta($postID, 'mec_start_datetime', $ics_event['start']);
            update_post_meta($postID, 'mec_end_datetime', $ics_event['end']);
            update_post_meta($postID, 'mec_start_day_seconds', $ics_time_start);
            update_post_meta($postID, 'mec_end_day_seconds', $ics_time_end);
        }

        return true;
    }

    return false;
}

// get all MEC events where there's a valid it and title
function get_mec_events($categoryName)
{
    $args = [
        'post_type' => 'mec-events',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'tax_query' => [
            [
                'taxonomy' => 'mec_category',
                'field' => 'slug',
                'terms' => $categoryName,
            ],
        ],
        'meta_key' => 'mec_ics_uid',
    ];

    $query = new WP_Query($args);

    $valid_posts = array_filter($query->posts, function ($post) {
        return is_object($post) && isset($post->ID) && isset($post->post_title);
    });

    return $valid_posts;
}

// Function to parse ICS content and extract event data.  Also ignores 2nd instance of DESCRIPTION tag in ics
function parse_ics_content($ics_content)
{
    $events_data = [];

    // Split the content into individual events
    $events = explode('BEGIN:VEVENT', $ics_content);

    // Remove the first element (it's empty)
    array_shift($events);

    foreach ($events as $event) {
        // Extract individual lines from the event
        $lines = explode("\n", $event);

        $event_data = [];

        // Flag to skip the second 'DESCRIPTION' occurrence
        $skip_description = false;

        foreach ($lines as $line) {
            // Split the line into property and value
            $lineParts = explode(':', $line, 2);

            // Check if $lineParts is an array with at least two elements
            if (is_array($lineParts) && count($lineParts) >= 2) {
                list($property, $value) = $lineParts;

                // Check if it's the 'DESCRIPTION' property
                if ($property === 'DESCRIPTION' && $skip_description) {
                    continue; // Skip the second 'DESCRIPTION' occurrence
                }

                // Handle each property
                switch ($property) {
                    case 'DTSTART':
                        // Use the date directly from the .ics file
                        $event_data['start'] = gmdate('Y-m-d H:i:s', strtotime($value));

                        // Extract the time and convert it to seconds
                        $event_data['time_start'] = strtotime($value) - strtotime(gmdate('Y-m-d 00:00:00'));
                        break;
                    case 'DTEND':
                        $event_data['end'] = gmdate('Y-m-d H:i:s', strtotime($value));

                        // Extract the time and convert it to seconds
                        $event_data['time_end'] = strtotime($value) - strtotime(gmdate('Y-m-d 00:00:00'));
                        break;
                    case 'SUMMARY':
                        $event_data['title'] = $value;
                        break;
                    case 'DESCRIPTION':
                        $event_data['description'] = $value;
                        $skip_description = true; // Set the flag to skip the second occurrence
                        break;
                    case 'LOCATION':
                        $event_data['location'] = $value;
                        break;
                    case 'UID':
                        $event_data['uid'] = $value;
                        break;
                }
            } else {
                // not good
            }
        }

        $event_data['repeat'] = 0;
        $event_data['days'] = '';
        $event_data['not_in_days'] = '';

        // Add the parsed event data to the array
        $events_data[] = $event_data;
    }

    return $events_data;
}

// Function to create a new WordPress post for an event
function create_event_post($event, $categoryName)
{
    $post_data = [
        'post_title' => $event['title'],
        'post_content' => $event['description'],
        'post_status' => 'publish',
        'post_type' => 'mec-events',
    ];

    if (DEBUG) {
        //error_log('Post content: ' . print_r($event['description'], true));
    }

    // Insert the post into the database
    $post_id = wp_insert_post($post_data);

    if ($post_id) {
        // Convert start and end times to seconds since midnight
        $eventStartTime = strtotime($event['start']);
        $eventEndTime = strtotime($event['end']);

        // Calculate time in seconds since midnight for start and end
        $time_start_seconds = date('H', $eventStartTime) * 3600 + date('i', $eventStartTime) * 60 + date('s', $eventStartTime);
        $time_end_seconds = date('H', $eventEndTime) * 3600 + date('i', $eventEndTime) * 60 + date('s', $eventEndTime);

        // Define the date array
        $date_array = [
            'start' => [
                'date' => date('Y-m-d', $eventStartTime),
                'hour' => date('g', $eventStartTime),
                'minutes' => date('i', $eventStartTime),
                'ampm' => date('A', $eventStartTime),
            ],
            'end' => [
                'date' => date('Y-m-d', $eventEndTime),
                'hour' => date('g', $eventEndTime),
                'minutes' => date('i', $eventEndTime),
                'ampm' => date('A', $eventEndTime),
            ],
            'comment' => '',
            'repeat' => [
                'type' => 'daily',
                'interval' => '1',
                'advanced' => '',
                'end' => 'never',
                'end_at_date' => '',
                'end_at_occurrences' => '10',
            ],
        ];

        // Serialize the date array
        $serialized_date_array = serialize($date_array);

        // Array of event meta data
        $meta_data = [
            ['meta_key' => 'mec_ics_uid', 'meta_value' => $event['uid']],
            ['meta_key' => 'mec_start_date', 'meta_value' => date('Y-m-d', $eventStartTime)],
            ['meta_key' => 'mec_start_time_hour', 'meta_value' => date('g', $eventStartTime)],
            ['meta_key' => 'mec_start_time_minutes', 'meta_value' => date('i', $eventStartTime)],
            ['meta_key' => 'mec_start_time_ampm', 'meta_value' => date('A', $eventStartTime)],
            ['meta_key' => 'mec_start_datetime', 'meta_value' => date('Y-m-d h:i A', $eventStartTime)],
            ['meta_key' => 'mec_start_day_seconds', 'meta_value' => $time_start_seconds],
            ['meta_key' => 'mec_end_date', 'meta_value' => date('Y-m-d', $eventEndTime)],
            ['meta_key' => 'mec_end_time_hour', 'meta_value' => date('g', $eventEndTime)],
            ['meta_key' => 'mec_end_time_minutes', 'meta_value' => date('i', $eventEndTime)],
            ['meta_key' => 'mec_end_time_ampm', 'meta_value' => date('A', $eventEndTime)],
            ['meta_key' => 'mec_end_datetime', 'meta_value' => date('Y-m-d h:i A', $eventEndTime)],
            ['meta_key' => 'mec_end_day_seconds', 'meta_value' => $time_end_seconds],
            ['meta_key' => 'mec_allday', 'meta_value' => '0'],
            ['meta_key' => 'one_occurrence', 'meta_value' => '0'],
            ['meta_key' => 'mec_hide_time', 'meta_value' => '0'],
            ['meta_key' => 'mec_hide_end_time', 'meta_value' => '0'],
            ['meta_key' => 'mec_timezone', 'meta_value' => 'global'],
            ['meta_key' => 'mec_countdown_method', 'meta_value' => 'global'],
            ['meta_key' => 'mec_style_per_event', 'meta_value' => 'global'],
            ['meta_key' => 'mec_repeat_status', 'meta_value' => '0'],
            ['meta_key' => 'mec_repeat_interval', 'meta_value' => '1'],
            ['meta_key' => 'mec_repeat_end_at_occurrences', 'meta_value' => '9'],
            ['meta_key' => 'mec_sequence', 'meta_value' => '1'],
            ['meta_key' => 'mec_date', 'meta_value' => $serialized_date_array],
            ['meta_key' => 'mec_organizer_id', 'meta_value' => '1'],
            ['meta_key' => 'mec_public', 'meta_value' => '1'],
            ['meta_key' => 'mec_repeat_type', 'meta_value' => ''],
            ['meta_key' => 'mec_repeat_end', 'meta_value' => ''],
            ['meta_key' => 'mec_repeat_end_at_date', 'meta_value' => ''],
            //array('meta_key' => 'mec_color', 'meta_value' => ''),
            //array('meta_key' => 'mec_dont_show_map', 'meta_value' => '0'),
            //array('meta_key' => 'mec_read_more', 'meta_value' => ''),
            //array('meta_key' => 'mec_more_info', 'meta_value' => ''),
            //array('meta_key' => 'mec_more_info_title', 'meta_value' => ''),
            //array('meta_key' => 'mec_more_info_target', 'meta_value' => '_self'),
            //array('meta_key' => 'mec_cost', 'meta_value' => ''),
            //array('meta_key' => 'mec_cost_auto_calculate', 'meta_value' => '0'),
            //array('meta_key' => 'mec_currency', 'meta_value' => 'a:0:{}'),
            //array('meta_key' => 'mec_additional_organizer_ids', 'meta_value' => 'a:0:{}'),
            //array('meta_key' => 'mec_additional_location_ids', 'meta_value' => 'a:0:{}'),
            //array('meta_key' => 'mec_repeat', 'meta_value' => 'a:6:{s:4:"type";s:5:"daily";s:8:"interval";s:1:"1";s:8:"advanced";s:0:"";s:3:"end";s:5:"never";s:11:"end_at_date";s:0:"";s:18:"end_at_occurrences";s:2:"10";}'),
            //array('meta_key' => 'mec_certain_weekdays', 'meta_value' => 'a:0:{}'),
            //array('meta_key' => 'mec_comment', 'meta_value' => ''),
            //array('meta_key' => 'mec_trailer_url', 'meta_value' => ''),
            //array('meta_key' => 'mec_trailer_title', 'meta_value' => ''),
            //array('meta_key' => 'mec_advanced_days', 'meta_value' => 'a:0:{}'),
            //array('meta_key' => 'mec_in_days', 'meta_value' => ''),
            //array('meta_key' => 'mec_not_in_days', 'meta_value' => ''),
            //array('meta_key' => 'mec_booking', 'meta_value' => 'a:12:{s:24:"bookings_limit_unlimited";s:1:"0";s:14:"bookings_limit";s:0:"";s:28:"bookings_minimum_per_booking";s:1:"1";s:24:"bookings_all_occurrences";s:1:"0";s:33:"bookings_all_occurrences_multiple";s:1:"0";s:26:"show_booking_form_interval";s:0:"";s:35:"stop_selling_after_first_occurrence";s:1:"0";s:11:"auto_verify";s:6:"global";s:12:"auto_confirm";s:6:"global";s:29:"bookings_booking_button_label";s:0:"";s:29:"bookings_user_limit_unlimited";s:1:"1";s:19:"bookings_user_limit";s:0:"";}'),
            //array('meta_key' => 'mec_tickets', 'meta_value' => 'a:0:{}'),
            //array('meta_key' => 'mec_fees_global_inheritance', 'meta_value' => '1'),
            //array('meta_key' => 'mec_fees', 'meta_value' => 'a:0:{}'),
            //array('meta_key' => 'mec_ticket_variations_global_inheritance', 'meta_value' => '1'),
            //array('meta_key' => 'mec_ticket_variations', 'meta_value' => 'a:0:{}'),
            //array('meta_key' => 'mec_reg_fields_global_inheritance', 'meta_value' => '1'),
            //array('meta_key' => 'mec_reg_fields', 'meta_value' => 'a:0:{}'),
            //array('meta_key' => 'mec_bfixed_fields', 'meta_value' => 'a:0:{}')

            // this breaks shit when you uncomment it
            //array('meta_key' => 'mec_hourly_schedules', 'meta_value' => 'a:0:{}'),
        ];

        // Loop through meta data and add_post_meta
        foreach ($meta_data as $data) {
            add_post_meta($post_id, $data['meta_key'], $data['meta_value']);
        }

        // Set category
        $category_name = $categoryName;
        set_event_category($post_id, $category_name);

        if (DEBUG) {
            //error_log('location: ' . print_r($event['location']), true);
        }

        // Set the location
        if (isset($event['location'])) {
            $full_location = $event['location'];

            // Extract the first part of the location before the first comma
            $location_parts = explode(',', $full_location, 2);
            $location_name = isset($location_parts[0]) ? sanitize_title(trim($location_parts[0])) : '';
            $location_address = $full_location;

            if (DEBUG) {
                //error_log('location_name: ' . print_r($location_name), true);
                //error_log('location_address: ' . print_r($location_address), true);
            }

            // Call the function to set location terms and create term relationship
            set_location_terms($post_id, $location_name, $location_address);
        }

        // Finally, import event data into custom tables
        // Update the $event_data array with the post_id
        $event['post_id'] = $post_id;

        import_events_to_mec_tables($event);

        // trigger update of post
        //wp_update_post(['ID' => $post_id]);
        wp_update_post(get_post($post_id, ARRAY_A));
    } else {
        // Log an error
        error_log('Post creation failed');
    }

    return $post_id;
}

function set_event_category($post_id, $category_name)
{
    if (DEBUG) {
        //error_log('category_name: ' . print_r($category_name, true));
    }

    // Check if the category exists
    $category_exists = term_exists($category_name, 'mec_category');

    if ($category_exists !== 0 && $category_exists !== null) {
        // Assign the category to the post
        wp_set_object_terms($post_id, $category_name, 'mec_category', false);
    } else {
        // Create the category
        $category_args = [
            'taxonomy' => 'mec_category',
            'cat_name' => $category_name,
        ];

        $category = wp_insert_term($category_name, 'mec_category', $category_args);

        if (!is_wp_error($category)) {
            // Assign the category to the post
            wp_set_object_terms($post_id, $category['term_id'], 'mec_category', false);
        } else {
            // Log an error
            error_log('Category creation failed: ' . $category->get_error_message());
        }
    }
}

// Function to set location terms and create term relationship
function set_location_terms($post_id, $location_name, $location_address)
{
    // Capitalize the first letter of each word and replace hyphens with spaces
    $location_name = ucwords(str_replace('-', ' ', $location_name));

    // Get all terms in mec_location taxonomy
    $location_terms = get_terms([
        'taxonomy' => 'mec_location',
        'hide_empty' => false,
    ]);

    // Check if the location name exists in mec_location taxonomy
    $existing_location = wp_list_filter($location_terms, ['name' => $location_name]);

    if (DEBUG) {
        //error_log('existing_location: ' . print_r($existing_location), true);
    }

    if ($existing_location) {
        // Get the first matching term
        $location_term = reset($existing_location);

        // Assign the location name to the post and create the term relationship
        wp_set_object_terms($post_id, $location_term->term_id, 'mec_location', false);

        // Insert location address into wp_termmeta if the location term is set
        if (!empty($location_address)) {
            add_term_meta($location_term->term_id, 'address', $location_address, true);

            // Add mec_location_id to wp_postmeta for the post
            add_post_meta($post_id, 'mec_location_id', $location_term->term_id, true);
        }
    } else {
        // Create the location name term
        $location_name_args = [
            'slug' => sanitize_title($location_name),
            'description' => $location_name,
        ];

        $location_name_term = wp_insert_term($location_name, 'mec_location', $location_name_args);

        if (!is_wp_error($location_name_term)) {
            // Assign the location name to the post and create the term relationship
            wp_set_object_terms($post_id, $location_name_term['term_id'], 'mec_location', false);

            // Insert location address into wp_termmeta if the location term is set
            if (!empty($location_address)) {
                add_term_meta($location_name_term['term_id'], 'address', $location_address, true);

                // Add mec_location_id to wp_postmeta for the post
                add_post_meta($post_id, 'mec_location_id', $location_name_term['term_id'], true);
            }
        } else {
            // Log an error
            error_log('Location name creation failed: ' . $location_name_term->get_error_message());
        }
    }
}

/// Function to import events data into the custom table
function import_events_to_mec_tables($event_data)
{
    global $wpdb;

    if (DEBUG) {
        //error_log('Event Data: ' . print_r($event_data, true));
        //error_log('SQL Query: ' . $wpdb->last_query);
    }

    // Table name
    $events_table_name = $wpdb->prefix . 'mec_events';
    $dates_table_name = $wpdb->prefix . 'mec_dates';

    // Convert start and end times to seconds since midnight
    $eventStartTime = strtotime($event_data['start']);
    $eventEndTime = strtotime($event_data['end']);

    // Calculate time in seconds since midnight for start and end
    $time_start_seconds = date('H', $eventStartTime) * 3600 + date('i', $eventStartTime) * 60 + date('s', $eventStartTime);
    $time_end_seconds = date('H', $eventEndTime) * 3600 + date('i', $eventEndTime) * 60 + date('s', $eventEndTime);

    // Check if key exists in $event_data
    $rinterval = isset($event_data['rinterval']) ? $event_data['rinterval'] : null;
    $year = isset($event_data['year']) ? $event_data['year'] : null;
    $month = isset($event_data['month']) ? $event_data['month'] : null;
    $day = isset($event_data['day']) ? $event_data['day'] : null;
    $week = isset($event_data['week']) ? $event_data['week'] : null;
    $weekday = isset($event_data['weekday']) ? $event_data['weekday'] : null;
    $weekdays = isset($event_data['weekdays']) ? $event_data['weekdays'] : null;

    // Iterate through events and insert into the table
    $result_events = $wpdb->insert(
        $events_table_name,
        [
            'post_id' => $event_data['post_id'],
            'start' => $event_data['start'],
            'end' => $event_data['end'],
            'repeat' => $event_data['repeat'],
            'rinterval' => $rinterval,
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'week' => $week,
            'weekday' => $weekday,
            'weekdays' => $weekdays,
            'days' => $event_data['days'],
            'not_in_days' => $event_data['not_in_days'],
            'time_start' => $time_start_seconds,
            'time_end' => $time_end_seconds,
        ],
        ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d']
    );

    // Check for errors and log if necessary
    if ($result_events === false && DEBUG) {
        error_log('Events Insert Error: ' . $wpdb->last_error);
    }

    // Insert into wp_mec_dates table with the calculated tstart and tend
    $wpdb->insert(
        $dates_table_name,
        [
            'post_id' => $event_data['post_id'],
            'dstart' => $event_data['start'],
            'dend' => $event_data['end'],
            'tstart' => $eventStartTime,
            'tend' => $eventEndTime,
            'status' => 'publish',
            'public' => 1,
        ],
        ['%d', '%s', '%s', '%d', '%d', '%s', '%d']
    );
}

function delete_all_events($future_only = true)
{
    global $wpdb;

    // Get the current date and time in the MySQL datetime format
    $current_datetime = current_time('mysql', 1);

    // Query to get post IDs of mec-events posts
    $post_ids_to_delete = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type = %s",
            'mec-events'
        )
    );

    if (DEBUG) {
        error_log('Deleting events with IDs: ' . implode(', ', $post_ids_to_delete));
    }

    // If future_only is true, filter out posts with a post_date before the current date
    if ($future_only) {
        $post_ids_to_delete = array_filter($post_ids_to_delete, function ($post_id) use ($current_datetime) {
            $post_date = get_post_field('post_date', $post_id, 'raw');
            return strtotime($post_date) >= strtotime($current_datetime);
        });
    }

    // Delete posts and associated post meta
    foreach ($post_ids_to_delete as $post_id) {
        wp_delete_post($post_id, true); // Set the second parameter to true to force delete
    }

    // Query to delete entries in mec_events table for the selected events
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mec_events
            WHERE post_id IN (%s)",
            implode(',', $post_ids_to_delete)
        )
    );

    // Get orphaned 'mec_' postmeta IDs
    $mec_postmeta_ids = $wpdb->get_col(
        "SELECT pm.meta_id
    FROM {$wpdb->postmeta} pm
    LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
    WHERE p.ID IS NULL
    AND pm.meta_key LIKE 'mec_%'"
    );

    // Delete orphaned 'mec_' post meta records based on meta_id instead of post_id
    if (!empty($mec_postmeta_ids)) {
        foreach ($mec_postmeta_ids as $meta_id) {
            delete_metadata_by_mid('post', $meta_id);
        }
    }
}
