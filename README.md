# Local Domain Manager

Local Domain Manager is a small PHP tool for Windows + XAMPP that helps you create and manage local domains for Apache projects such as plain PHP apps or Laravel projects.

It updates:

- the Windows `hosts` file
- Apache `httpd.conf`
- Apache `httpd-vhosts.conf`

With this tool, you can quickly map domains like `myproject.local` or `app.test` to a local project folder without manually editing Apache and hosts files every time.

## What this project does

This project provides a web interface for:

- adding a new local domain
- assigning that domain to a project folder
- selecting a port for Apache
- listing all configured local domains
- deleting a domain safely
- opening the mapped folder in Windows File Explorer
- checking folder details like size, item count, last modified time, and whether it is a Git repository
- copying project paths and URLs from the UI
- switching between light and dark theme

## Repository structure

This repository currently contains one main file:

### `localdomain.php`

This is the complete application file. It contains:

#### 1. Configuration paths
At the top of the file, these variables define the Windows and XAMPP files the app manages:

- `C:\Windows\System32\drivers\etc\hosts`
- `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
- `C:\xampp\apache\conf\httpd.conf`

If your XAMPP or Apache is installed in a different location, you must update these paths.

#### 2. Helper functions
These helper functions support the app:

- `normalize($p)` → normalizes Windows paths
- `escape($value)` → safely escapes output for HTML
- `redirectWithFlash(...)` → redirects back with a success or error message
- `jsonResponse(...)` → returns JSON for AJAX/fetch requests
- `openFolderInExplorer($path)` → opens the target folder in Windows Explorer
- `formatBytes($bytes)` → converts file size into readable format
- `collectFolderMeta($path)` → collects folder metadata such as size, item count, modification time, and Git status

#### 3. Apache listen-port management
These functions manage Apache `Listen` directives:

- `addListenPort($file, $port)` → adds `Listen <port>` into `httpd.conf` if missing
- `removeListenPortIfUnused($vhostFile, $httpdFile, $port)` → removes the port from `httpd.conf` if no remaining virtual host uses it

#### 4. Hosts file management
These functions manage the Windows hosts file:

- `addHost($file, $domain)` → adds `127.0.0.1 domain`
- `removeHost($file, $domain)` → removes host entries for a domain

#### 5. Apache virtual host management
These functions manage the virtual host blocks inside `httpd-vhosts.conf`:

- `addVhost($file, $domain, $path, $port)` → appends a new `<VirtualHost>` block
- `removeVhostAndReturnPort($file, $domain)` → removes a domain’s virtual host block and returns the port that was used

#### 6. Reading configured domains
- `readAllDomains($file)` parses the Apache vhost file and builds the list shown in the UI.
- It only includes domains ending in `.local` or `.test`.
- It ignores `localhost`.

#### 7. Request handling and actions
The file handles different actions:

- `action=folder_meta` returns folder info as JSON
- POST `action=add` creates a new local domain
- POST `action=delete` removes an existing domain
- POST `action=open_folder` opens the mapped path in Explorer

#### 8. Frontend UI
The same file also contains the entire frontend:

- HTML layout
- Tailwind CSS-based styling
- Bootstrap icons
- jQuery
- Selectize dropdown for port selection
- JavaScript for:
  - search/filtering existing domains
  - delete confirmation modal
  - folder info modal
  - copy-to-clipboard buttons
  - dark/light theme toggle
  - folder metadata fetch and cookie caching

## Requirements

Before using this project, make sure you have:

- Windows
- XAMPP with Apache installed
- PHP enabled through XAMPP
- Administrator access
- permission to edit:
  - `C:\Windows\System32\drivers\etc\hosts`
  - `C:\xampp\apache\conf\httpd.conf`
  - `C:\xampp\apache\conf\extra\httpd-vhosts.conf`

## Important notes before setup

1. Run XAMPP or Apache with **Administrator** privileges.
2. Make sure Apache virtual hosts are enabled.
3. Make sure Apache is allowed to use the ports you select.
4. If your projects are Laravel apps, use the `public` folder as the document root when needed.
5. This tool is Windows-specific because it uses Windows file paths and File Explorer commands.

## Setup steps

### Step 1: Place the file in your web server directory
Copy `localdomain.php` into a folder inside your XAMPP web root, for example:

```text
C:\xampp\htdocs\localdomain-manager\localdomain.php
```

### Step 2: Start XAMPP as Administrator
Open XAMPP Control Panel using **Run as administrator**.

This is required because the tool needs permission to update the hosts file and Apache config files.

### Step 3: Enable Apache virtual hosts
Open this file:

```text
C:\xampp\apache\conf\httpd.conf
```

Make sure this line is enabled and not commented out:

```apache
Include conf/extra/httpd-vhosts.conf
```

If it starts with `#`, remove the `#`.

### Step 4: Check file paths inside `localdomain.php`
At the top of `localdomain.php`, verify these values match your system:

```php
$HOST_FILE = 'C:\Windows\System32\drivers\etc\hosts';
$VHOST_FILE = 'C:\xampp\apache\conf\extra\httpd-vhosts.conf';
$HTTPD_CONF = 'C:\xampp\apache\conf\httpd.conf';
```

If your XAMPP installation path is different, edit them accordingly.

### Step 5: Open the tool in your browser
Visit:

```text
http://localhost/localdomain-manager/localdomain.php
```

Adjust the path if you placed the file in a different folder.

### Step 6: Add a domain
In the form:

1. Enter a domain such as `project.local` or `project.test`
2. Enter the full project path such as:

```text
C:/xampp/htdocs/project
```

or for Laravel:

```text
C:/xampp/htdocs/project/public
```

3. Choose a port like `80`, `8080`, or another free port
4. Click **Add Domain**

### Step 7: Restart Apache
After creating or deleting a domain, restart Apache from XAMPP Control Panel.

This is necessary for the Apache configuration changes to take effect.

## How to use

### Add a domain
- Enter a `.local` or `.test` domain
- Enter a valid project folder path
- Choose the port
- Submit the form

The tool will:
- add the domain to the hosts file
- add the port to Apache if needed
- create a virtual host entry

### View existing domains
The table shows:
- domain name
- port
- mapped path
- actions

### Open project folder
Use the folder button to open the mapped directory in Windows Explorer.

### Copy path or URL
Use the copy buttons to copy:
- the local project path
- the full browser URL

### View folder info
Use the **Info** button to view:
- folder size
- number of items
- Git repo status
- folder existence status
- last modified information

### Delete a domain
Use the **Delete** button to:
- remove the domain from the hosts file
- remove the matching Apache virtual host block
- remove the Apache listen port if it is no longer used

Then restart Apache.

## Allowed domain format

The app only accepts domains in this format:

- lowercase letters
- numbers
- hyphen
- must end with `.local` or `.test`

Examples:

- `myapp.local`
- `laravel-blog.test`

Invalid examples:

- `MyApp.local`
- `app.dev`
- `test_site.local`

## Example workflow

For a Laravel project located at:

```text
C:/xampp/htdocs/blog/public
```

You can add:

- Domain: `blog.local`
- Port: `80`
- Path: `C:/xampp/htdocs/blog/public`

After restarting Apache, open:

```text
http://blog.local
```

If you use port `8080`, open:

```text
http://blog.local:8080
```

## Limitations

- Windows only
- designed for XAMPP Apache structure
- currently uses a single PHP file for backend and frontend
- no authentication system
- config file editing assumes standard Apache file formatting

## Suggested future improvements

You may want to improve this project later by adding:

- separate CSS/JS/PHP files
- backup creation before modifying system files
- validation for duplicate domain + path combinations
- editable existing domain entries
- custom Apache options like SSL or directory index settings
- support for non-XAMPP Apache installations
- Linux/macOS support

## Troubleshooting

### Apache changes do not work
- confirm Apache was restarted
- confirm `httpd-vhosts.conf` is included from `httpd.conf`
- check if the selected port is already in use

### Permission denied
- run XAMPP as administrator
- ensure PHP has permission to write to the hosts and Apache config files

### Domain does not open
- verify the hosts file contains the domain
- verify the virtual host block was written correctly
- confirm the project path exists
- confirm Apache is running

### Laravel project shows wrong page
- use the Laravel `public` directory as the document root

## License

No license file is currently included in this repository. Add one if you want to define reuse permissions.
