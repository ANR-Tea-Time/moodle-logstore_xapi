# [Moodle Logstore xAPI](https://moodle.org/plugins/view/logstore_xapi)
> Emits [xAPI](https://github.com/adlnet/xAPI-Spec/blob/master/xAPI.md) statements using the [Moodle](https://moodle.org/) Logstore.

- Install the plugin using [our zip installation guide](/docs/install-with-zip.md).
- Process events before the plugin was installed using [our historical events guide](/docs/historical-events.md).
- Ask questions via the [Github issue tracker](https://github.com/xAPI-vle/moodle-logstore_xapi/issues).
- Report bugs and suggest features with the [Github issue tracker](https://github.com/xAPI-vle/moodle-logstore_xapi/issues).
- View the supported events in [our `get_event_function_map` function](/src/transformer/get_event_function_map.php).
- Change existing statements for the supported events using [our change statements guide](/docs/change-statements.md).
- Create statements using [our new statements guide](/docs/new-statements.md).

# Configurations needed for new functionalities (activate or deactivate logs for courses and GDPR)

## Activate or deactivate logs for courses

- Go to Site administration -> Courses -> Default settings -> Course custom fields.
- Click on the "Add a new category" button and change the name of the category to "xAPI Logging" for example.
- Click on the "Add a new custom field" button and choose a "Name" and "Short name" for the field (Enable xAPI logging and enable_xapi_loggin for example) and click on "Save changes".

PS : Keep the shortname in mind because it is required in the plugin settings later.

## GDPR

- Go to Site administration -> Users -> Privacy and policies -> Policy settings.
- Change the "Site policy handler" to Policies (tool_policy).
- Go to Site administration -> Users -> Privacy and policies -> Manage policies.
- Click on the "New policy" button and choose a "Name", "Summary" and "Full policy" for the policy. Set the "Agreement optional" setting to "Yes" and set the "Policy status" to "Active". Change the "User consent" option to "Authenticated users" if needed. Then click on "Save".

PS : Keep the policy name in mind because it is also required in the plugin settings later.

## Plugin settings

In addition to the basic settings of the plugin, the "Course custom field shortname" and "Policy name" fields should be filled in with the informations mentioned above.
