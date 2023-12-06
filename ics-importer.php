<?php
/*
Plugin Name: ICS Importer
Description: A plugin for importing events from ICS files into WordPress.  
BE SURE YOU DEFINE HARD-CODED CATEGORY VALUES AND URLS STARTING ON LINE 39 BELOW!
This code is provided “as is” without warranty of any kind, expressed or implied.
Version: 1.0
Author: Shaun Geisert
*/

// Check if WordPress is loaded
if (!defined('ABSPATH')) {
    wp_die('Direct script access denied.');
}

// Hook the function to check the URL and import events
add_action('ics_importer_cron_hook', 'ics_importer_import_events');

// Schedule the cron event to run every night
register_activation_hook(__FILE__, 'ics_importer_activate');
register_deactivation_hook(__FILE__, 'ics_importer_deactivate');

function ics_importer_activate()
{
    wp_schedule_event(time(), 'daily', 'ics_importer_cron_hook');
}

function ics_importer_deactivate()
{
    wp_clear_scheduled_hook('ics_importer_cron_hook');
}

// Function to import events from the ICS file into WordPress posts and custom table
function ics_importer_import_events()
{
    // Delete future events before importing new events
    delete_future_events();

    $climbingWallCategory = 3;
    $dropInSportsCategory = 4;

    // Do climbing wall
    ics_importer_run('https://wsnet2.colostate.edu/cwis199/everficourses/feed/climbingWall.ics', $climbingWallCategory);

    // Do drop-in sports
    ics_importer_run('https://wsnet2.colostate.edu/cwis199/everficourses/feed/dropinSports.ics', $dropInSportsCategory);
    
}

// Function to import events from the ICS file into WordPress posts and custom table
function ics_importer_run($url, $categoryId)
{
    // Get the ICS file content from the URL
    $ics_url = $url;
    $ics_content = file_get_contents($ics_url);

    // Parse the ICS content to extract event data
    $events_data = parse_ics_content($ics_content);

    // Import events into WordPress posts and custom table
    foreach ($events_data as $event) {
        //error_log('Debugging event:');
        //error_log(print_r($event, true));
        error_log('Debugging category id:' . $categoryId);

        // Create a new WordPress post for each event
        $post_id = create_event_post($event, $categoryId);

        // If the post was created successfully, insert data into the custom table
        if ($post_id) {
            $event['post_id'] = $post_id;
            import_events_to_mec_tables($event);
        }
    }
}

// Function to parse ICS content and extract event data
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

        foreach ($lines as $line) {
            // Split the line into property and value
            $lineParts = explode(':', $line, 2);

            // Check if $lineParts is an array with at least two elements
            if (is_array($lineParts) && count($lineParts) >= 2) {
                list($property, $value) = $lineParts;

                // Handle each property as needed
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
function create_event_post($event, $categoryId)
{
    $post_data = [
        'post_title' => $event['title'],
        'post_content' => $event['description'],
        'post_status' => 'publish',
        'post_type' => 'mec-events',
    ];

    // Insert the post into the database
    $post_id = wp_insert_post($post_data);

    if ($post_id) {
        // Insert into wp_postmeta table
        add_post_meta($post_id, 'mec_start_date', $event['start'], true);
        add_post_meta($post_id, 'mec_start_time_hour', date('g', $event['time_start']), true);
        add_post_meta($post_id, 'mec_start_time_minutes', date('i', $event['time_start']), true);
        add_post_meta($post_id, 'mec_start_time_ampm', date('A', $event['time_start']), true);
        //add_post_meta($post_id, 'mec_start_day_seconds', $event['time_start'], true);
        add_post_meta($post_id, 'mec_start_datetime', $event['start'] . ' ' . date('h:i A', $event['time_start']), true);
        add_post_meta($post_id, 'mec_end_date', $event['end'], true);
        add_post_meta($post_id, 'mec_end_time_hour', date('g', strtotime($event['end'] . ' ' . $event['time_end'])), true);
        add_post_meta($post_id, 'mec_end_time_minutes', date('i', strtotime($event['end'] . ' ' . $event['time_end'])), true);
        add_post_meta($post_id, 'mec_end_time_ampm', date('A', strtotime($event['end'] . ' ' . $event['time_end'])), true);
        //add_post_meta($post_id, 'mec_end_day_seconds', $event['time_end'], true);
        add_post_meta($post_id, 'mec_end_datetime', $event['end'] . ' ' . date('h:i A', strtotime($event['end'] . ' ' . $event['time_end'])), true);

        // Set the category
        $category_id = $categoryId;

        // Check if the category exists
        $category_exists = term_exists($category_id, 'mec_category');

        if ($category_exists !== 0 && $category_exists !== null) {
            // Assign the category to the post
            wp_set_object_terms($post_id, $category_id, 'mec_category', false);
        } else {
            // Log an error or handle the case where the category doesn't exist
            error_log('Category does not exist: ' . $category_id);
        }
    } else {
        // Log an error or handle the case where post creation fails
        error_log('Post creation failed');
    }

    return $post_id;
}


/// Function to import events data into the custom table
function import_events_to_mec_tables($event_data)
{
    global $wpdb;

    // Table name
    $events_table_name = $wpdb->prefix . 'mec_events';
    $dates_table_name = $wpdb->prefix . 'mec_dates';

    // Calculate the difference in seconds for tstart/tend
    $eventStartTime = strtotime($event_data['start']);
    $unixEpochStartTime = strtotime('1970-01-01 00:00:00');
    $eventEndTime = strtotime($event_data['end']);
    $differenceInSecondsStart = $eventStartTime - $unixEpochStartTime;
    $differenceInSecondsEnd = $eventEndTime - $unixEpochStartTime;

    // Check if key exists in $event_data
    $rinterval = isset($event_data['rinterval']) ? $event_data['rinterval'] : null;
    $year = isset($event_data['year']) ? $event_data['year'] : null;
    $month = isset($event_data['month']) ? $event_data['month'] : null;
    $day = isset($event_data['day']) ? $event_data['day'] : null;
    $week = isset($event_data['week']) ? $event_data['week'] : null;
    $weekday = isset($event_data['weekday']) ? $event_data['weekday'] : null;
    $weekdays = isset($event_data['weekdays']) ? $event_data['weekdays'] : null;

    // Iterate through events and insert into the table
    $wpdb->insert(
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
            'time_start' => $event_data['time_start'],
            'time_end' => $event_data['time_end'],
        ],
        ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d']
    );

    // Insert into wp_mec_dates table with the calculated tstart and tend
    $wpdb->insert(
        $dates_table_name,
        [
            'post_id' => $event_data['post_id'],
            'dstart' => $event_data['start'],
            'dend' => $event_data['end'],
            'tstart' => $differenceInSecondsStart, 
            'tend' => $differenceInSecondsEnd,
            'status' => 'publish',
            'public' => 1,
        ],
        ['%d', '%s', '%s', '%d', '%d', '%s', '%d']
    );
}



function delete_future_events()
{
    global $wpdb;

    // Get the current date and time in the MySQL datetime format
    $current_datetime = current_time('mysql', 1);

    // Query to get post IDs of mec-events posts in the future
    $post_ids_to_delete = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type = %s
            AND post_status = 'publish'
            AND post_date > %s",
            'mec-events',
            $current_datetime
        )
    );

    // Delete posts
    foreach ($post_ids_to_delete as $post_id) {
        wp_delete_post($post_id, true); // Set the second parameter to true to force delete
    }

    // Query to delete entries in mec_events table for future events
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mec_events
            WHERE post_id IN (%s)
            AND start > %s",
            implode(',', $post_ids_to_delete),
            $current_datetime
        )
    );
}
