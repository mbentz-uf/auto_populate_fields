<?php
/**
 * @file
 * Provides ExternalModule class for Auto Populate Fields.
 */

namespace AutoPopulateFields\ExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Form;
use LogicTester;
use Piping;
use Records;
use REDCap;

/**
 * ExternalModule class for Auto Populate Fields.
 */
class ExternalModule extends AbstractExternalModule {

    /**
     * @inheritdoc
     */
    function hook_every_page_top($project_id) {
        if (!$project_id) {
            return;
        }

        if (PAGE == 'Design/online_designer.php') {
            $this->includeJs('js/helper.js');
        }
        elseif (PAGE == 'DataEntry/index.php' && !empty($_GET['id'])) {
            if (!$this->currentFormHasData()) {
                $this->setDefaultValues();
            }

            if (isset($_GET['page']) && function_exists('getBranchingFields')) {
                $this->setDefaultWhenVisible();
            }
        }
    }

    /**
     * Extends @DEFAULT action tag.
     *
     * Features included:
     * - @DEFAULT_<N> action tag;
     * - @DEFAULT-FROM-PREVIOUS-EVENT action tag;
     * - @DEFAULT-FROM-PREVIOUS-EVENT_<N> action tag
     * - Piping on choice selection fields now returns the option key instead
     *   of the option label.
     */
    function setDefaultValues() {
        global $Proj, $user_rights, $double_data_entry;

        // Storing old metadata.
        $aux_metadata = $Proj->metadata;

        // Temporarily overriding project metadata.
        foreach ($Proj->metadata as $field_name => $field_info) {
            // Overriding choice selection fields - checkboxes, radios and dropdowns.
            if (!in_array($field_info['element_type'], array('checkbox', 'radio', 'select'))) {
                continue;
            }

            if (!$options = parseEnum($Proj->metadata[$field_name]['element_enum'])) {
                continue;
            }

            foreach (array_keys($options) as $key) {
                // Replacing selection choices labels with keys.
                $options[$key] = $key . ',' . $key;
            }

            $Proj->metadata[$field_name]['element_enum'] = implode('\\n', $options);
        }

        $action_tags_to_look = array('@DEFAULT');

        // Getting current record data, if exists.
        if ($data = REDCap::getData($Proj->project['project_id'], 'array', $_GET['id'])) {
            $data = $data[$_GET['id']];

            // Only consider @DEFAULT-FROM-PREVIOUS-EVENT action tag when the
            // record is already exists.
            array_unshift($action_tags_to_look, '@DEFAULT-FROM-PREVIOUS-EVENT');
        }

        $fields = empty($_GET['page']) ? $Proj->metadata : $Proj->forms[$_GET['page']]['fields'];
        $arm = $Proj->eventInfo[$_GET['event_id']]['arm_num'];
        $events = array_keys($Proj->events[$arm]['events']);
        $entry_num = ($double_data_entry && $user_rights['double_data'] != 0) ? '--' . $user_rights['double_data'] : '';

        foreach (array_keys($fields) as $field_name) {
            $field_info = $Proj->metadata[$field_name];
            $misc = $field_info['misc'];

            // Getting available action tags.
            $action_tags = $this->getMultipleActionTagsQueue($action_tags_to_look, $misc);
            if (empty($action_tags)) {
                continue;
            }

            $default_value = '';

            // Looping over @DEFAULT_<N> and @DEFAULT-FROM-PREVIOUS-EVENT_<N>
            // action tags.
            foreach ($action_tags as $action_tag) {
                if (strpos($action_tag, '@DEFAULT-FROM-PREVIOUS-EVENT') === 0) {
                    if (!$source_field = self::getValueInActionTag($misc, $action_tag)) {
                        // If no value is provided on the action tag, set the same
                        // field as source by default.
                        $source_field = $field_name;
                    }
                    elseif (!isset($Proj->metadata[$source_field])) {
                        // Invalid field.
                        continue;
                    }

                    // Getting previous event ID.
                    foreach ($events as $event) {
                        if ($event == $_GET['event_id']) {
                            break;
                        }

                        if (in_array($field_info['form_name'], $Proj->eventsForms[$event])) {
                            $prev_event = $event;
                        }
                    }

                    if (!isset($prev_event)) {
                        continue;
                    }

                    // Getting previous event value.
                    $default_value = isset($data[$prev_event][$source_field]) ? $data[$prev_event][$source_field] : '';

                    // Handling checkboxes case.
                    if (is_array($default_value)) {
                        $default_value = implode(',', array_keys(array_filter($default_value)));
                    }
                }
                else {
                    if ($default_value = Form::getValueInQuotesActionTag($misc, $action_tag)) {
                        // Steping ahead REDCap and piping strings in our way.
                        $default_value = Piping::replaceVariablesInLabel($default_value, $_GET['id'] . $entry_num, $_GET['event_id'], $_GET['instance'], array(), false, null, false);
                    }
                }

                if (empty($default_value) && !is_numeric($default_value)) {
                    continue;
                }

                // The first non empty default value wins!
                $misc = $this->overrideActionTag('@DEFAULT', $default_value, $misc);
                $aux_metadata[$field_name]['misc'] = $misc;

                break;
            }
        }

        // Now that pipings are done, let's restore original project metadata.
        $Proj->metadata = $aux_metadata;
    }

    /**
     * Enables Default When Visible functionality.
     *
     * Overrides branching logic behavior in order to permit @DEFAULT action
     * tags to work on hidden fields - without any alerts, making the default
     * value available when its field gets visible.
     *
     * Behavior changes:
     * - Branching logic alerts disabled;
     * - Field values are no longer erased when hidden by branching logic - they
     *   are now erased on form submission.
     *
     * @see js/default_when_visible.js
     */
    function setDefaultWhenVisible() {
        $equations = array();
        list($branching_fields, ) = getBranchingFields($_GET['page']);

        foreach ($branching_fields as $field => $equation) {
            list($equations[$field], ) = LogicTester::formatLogicToJS($equation, false, $_GET['event_id'], true);
        }

        $this->setJsSetting('defaultWhenVisible', array('branchingEquations' => $equations));
        $this->includeJs('js/default_when_visible.js');
    }

    /**
     * Checks if the current form has data.
     *
     * @return bool
     *   TRUE if the current form contains data, FALSE otherwise.
     */
    function currentFormHasData() {
        global $double_data_entry, $user_rights, $quesion_by_section, $pageFields;

        $record = $_GET['id'];
        if ($double_data_entry && $user_rights['double_data'] != 0) {
            $record = $record . '--' . $user_rights['double_data'];
        }

        if (PAGE != 'DataEntry/index.php' && $question_by_section && Records::fieldsHaveData($record, $pageFields[$_GET['__page__']], $_GET['event_id'])) {
            // The survey has data.
            return true;
        }

        if (Records::formHasData($record, $_GET['page'], $_GET['event_id'], $_GET['instance'])) {
            // The data entry has data.
            return true;
        }

        return false;
    }

    /**
     * Looks for multiple action tags in a given string.
     *
     * Example: @DEFAULT_<N> (e.g. @DEFAULT_1, @DEFAULT_2, etc).
     *
     * @param string|array $action_tags
     *   The action tag string (e.g. @DEFAULT), or an array of action tags.
     * @param string $subject
     *   The string to search in.
     *
     * @return array
     *   The sorted list of the fetched action tags.
     */
    function getMultipleActionTagsQueue($action_tags, $subject) {
        $results = array();

        if (is_string($action_tags)) {
            $action_tags = array($action_tags);
        }

        // Handling action tags placed at the end of string.
        $subject .= ' ';

        $action_tags = array_unique($action_tags);
        $priority = array_flip($action_tags);

        // Buiding aux array to assist the sorting procedure.
        $aux = array();
        foreach ($action_tags as $tag) {
            // Checking first for action tag without number suffix.
            if (strpos($subject, $tag . '=') !== false || strpos($subject, $tag . ' ') !== false) {
                $results[] = $tag;
            }

            preg_match_all('/(' . $tag . '_\d+)/', $subject, $matches);

            foreach ($matches[1] as $result) {
                list(, $delta) = explode('_', $result);

                if (!isset($aux[$delta])) {
                    $aux[$delta] = array();
                }

                $aux[$delta][$priority[$tag]] = $result;
            }
        }

        // Sorting action tags by suffix number (e.g. @DEFAULT, @DEFAULT_1,
        // @DEFAULT_2, etc).
        ksort($aux);
        foreach ($aux as $delta => $subset) {
            // When 2 or more action tags declare the same suffix number, the
            // criteria is to sort by the sequence defined in $action_tags
            // array.
            ksort($subset);
            foreach ($subset as $tag) {
                $results[] = $tag;
            }
        }

        return $results;
    }

    /**
     * Overrides an action tag value.
     *
     * @param string $key
     *   The action tag name (e.g. @DEFAULT).
     * @param string $value
     *   The value to replace with.
     * @param string $subject
     *   The subject string to override.
     * @param bool $append_if_not_exists
     *   Append the action tag value if the target to override was not found.
     *
     * @return string
     *   The overriden subject string.
     */
    function overrideActionTag($key, $value, $subject, $append_if_not_exists = true) {
        if (strpos($subject, $key . '=') !== false) {
            // Override action tag if exists.
            $regex = '/(' . $key . '\s*=\s*)((\"[^\"]+\")|(\'[^\']+\'))/';
            $subject = preg_replace($regex, $key . '="' . $value . '"', $subject);
        }
        elseif ($append_if_not_exists) {
            $subject .= ' ' . $key . '="' . $value . '"';
        }

        return $subject;
    }

    /**
     * Includes a local JS file.
     *
     * @param string $path
     *   The relative path to the js file.
     */
    protected function includeJs($path) {
        echo '<script src="' . $this->getUrl($path) . '"></script>';
    }

    /**
     * Sets a JS setting.
     *
     * @param string $key
     *   The setting key to be appended to the module settings object.
     * @param mixed $value
     *   The setting value.
     */
    protected function setJsSetting($key, $value) {
        echo '<script>autoPopulateFields = {' . $key . ': ' . json_encode($value) . '};</script>';
    }

    /**
     * Alternative version of Form::getValueInActionTags.
     *
     * Fixes problems with single quoted values.
     *
     * @see Form::getValueInActionTags()
     */
    public static function getValueInActionTag($subject, $action_tag) {
        if ($value = Form::getValueInQuotesActionTag($subject, $action_tag)) {
            return $value;
        }

        preg_match('/' . $action_tag .'\s*=\s*([^\s]+)/', $subject, $matches);
        if (empty($matches[1])) {
            return '';
        }

        return $matches[1];
    }
}
