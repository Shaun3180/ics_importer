<?php
/*
Plugin Name: DSA ICS Importer
Description: A plugin for importing events from ICS files into WordPress.  

BE SURE YOU DEFINE HARD-CODED CATEGORY VALUES AND URLS STARTING ON LINE 40 BELOW!

This code is provided “as is” without warranty of any kind, expressed or implied.
Version: 1.0
Author: Shaun Geisert
*/

// Check if WordPress is loaded
if (!defined('ABSPATH')) {
    wp_die('Direct script access denied.');
}

// Define constant for debugging
define('DEBUG', false); 

// Hook the function to check the URL and import events
add_action('ics_importer_cron_hook', 'ics_importer_cron_callback', 10, 2);

// Schedule the cron event to run every night
register_activation_hook(__FILE__, 'ics_importer_activate');
register_deactivation_hook(__FILE__, 'ics_importer_deactivate');

function ics_importer_deactivate()
{
    wp_clear_scheduled_hook('ics_importer_cron_hook');
}

function ics_importer_activate()
{
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
        'Red Cross Classes' => 'https://wsprod.colostate.edu/cwis199/everficourses/feed/redCross.ics',
    ];

    $delay = 0;

    // Schedule separate cron events for each category
    foreach ($categories as $categoryName => $categoryUrl) {
        if ($categoryName === 'Drop-in Swim') {
            // Schedule "Drop-in Swim" to run every hour
            wp_schedule_event(time() + $delay, 'hourly', 'ics_importer_cron_hook', [$categoryUrl, $categoryName]);
        } else {
            // Schedule other categories to run daily
            wp_schedule_event(time() + $delay, 'twicedaily', 'ics_importer_cron_hook', [$categoryUrl, $categoryName]);
        }
        $delay += 60;
    }
}

// Handle a specific category
function ics_importer_cron_callback($categoryUrl, $categoryName)
{
    //delete_all_events(false);
    //return;

    // Run the import for the specific category
    ics_importer_run($categoryUrl, $categoryName);
}

// Accept category-specific parameters
function ics_importer_run($url, $categoryName)
{
    delete_events(true, $categoryName);

    // Get the ICS file content from the URL
    $ics_url = $url;
    $ics_content = file_get_contents($ics_url);

    // Parse the ICS content to extract event data
    $events_data = parse_ics_content($ics_content);

    // Import events into WordPress posts and custom table
    foreach ($events_data as $event) {
        //error_log('Debugging event:');
        //error_log(print_r($event, true));
        
        // Create a new WordPress post for each event
        $post_id = create_event_post($event, $categoryName);

        if (DEBUG) {
            error_log('post_id:' . $post_id);
        }

        // If the post was created successfully, insert data into the custom table
        if ($post_id) {
            $event['post_id'] = $post_id;

            import_events_to_mec_tables($event);
        }
    }
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
    
        // Insert into wp_postmeta table
        add_post_meta($post_id, 'mec_start_date', date('Y-m-d', $eventStartTime), true);
        add_post_meta($post_id, 'mec_start_time_hour', date('g', $eventStartTime), true);
        add_post_meta($post_id, 'mec_start_time_minutes', date('i', $eventStartTime), true);
        add_post_meta($post_id, 'mec_start_time_ampm', date('A', $eventStartTime), true);
        add_post_meta($post_id, 'mec_start_datetime', date('Y-m-d h:i A', $eventStartTime), true);
        add_post_meta($post_id, 'mec_start_day_seconds', $time_start_seconds, true);
    
        add_post_meta($post_id, 'mec_end_date', date('Y-m-d', $eventEndTime), true);
        add_post_meta($post_id, 'mec_end_time_hour', date('g', $eventEndTime), true);
        add_post_meta($post_id, 'mec_end_time_minutes', date('i', $eventEndTime), true);
        add_post_meta($post_id, 'mec_end_time_ampm', date('A', $eventEndTime), true);
        add_post_meta($post_id, 'mec_end_datetime', date('Y-m-d h:i A', $eventEndTime), true);
        add_post_meta($post_id, 'mec_end_day_seconds', $time_end_seconds, true);

        // Define the date array
        $date_array = array(
            'start' => array(
                'date' => date('Y-m-d', $eventStartTime),
                'hour' => date('g', $eventStartTime),
                'minutes' => date('i', $eventStartTime),
                'ampm' => date('A', $eventStartTime)
            ),
            'end' => array(
                'date' => date('Y-m-d', $eventEndTime),
                'hour' => date('g', $eventEndTime),
                'minutes' => date('i', $eventEndTime),
                'ampm' => date('A', $eventEndTime)
            ),
            'comment' => '',
            'repeat' => array(
                'type' => 'daily',
                'interval' => '1',
                'advanced' => '',
                'end' => 'never',
                'end_at_date' => '',
                'end_at_occurrences' => '10'
            )
        );

        // Serialize the date array
        $serialized_date_array = serialize($date_array);

        // Insert 'mec_date' into wp_postmeta table
        add_post_meta($post_id, 'mec_date', $serialized_date_array, true);

        // Add mec_organizer_id
        add_post_meta($post_id, 'mec_organizer_id', 1, true);

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

    
    } else {
        // Log an error or handle the case where post creation fails
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
function set_location_terms($post_id, $location_name, $location_address) {
    // Capitalize the first letter of each word and replace hyphens with spaces
    $location_name = ucwords(str_replace('-', ' ', $location_name));

    // Get all terms in mec_location taxonomy
    $location_terms = get_terms(array(
        'taxonomy' => 'mec_location',
        'hide_empty' => false,
    ));

    // Check if the location name exists in mec_location taxonomy
    $existing_location = wp_list_filter($location_terms, array('name' => $location_name));

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
            'time_start' => $time_start_seconds,
            'time_end' => $time_end_seconds,
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
            'tstart' => $eventStartTime,
            'tend' => $eventEndTime,
            'status' => 'publish',
            'public' => 1,
        ],
        ['%d', '%s', '%s', '%d', '%d', '%s', '%d']
    );
}


function delete_events($future_only = true, $category_name = null)
{
    global $wpdb;

    // Get the current date and time in the MySQL datetime format
    $current_datetime = current_time('mysql', 1);

    // Query to get post IDs of mec-events posts with future start dates
    $post_ids_to_delete = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT e.post_id
            FROM {$wpdb->prefix}mec_events e
            INNER JOIN {$wpdb->posts} p ON e.post_id = p.ID
            WHERE p.post_type = %s
            AND e.start >= %s",
            'mec-events',
            $current_datetime
        )
    );

    // If a category_name is specified, filter out posts that do not belong to that category
    if ($category_name) {
        $term = get_term_by('name', $category_name, 'mec_category');
        if ($term) {
            $post_ids_to_delete = array_filter($post_ids_to_delete, function ($post_id) use ($term) {
                return has_term($term->term_id, 'mec_category', $post_id);
            });
        }
    }

    // Delete posts
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

    // Delete posts
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
}
