Feature: HTTP request mocking

  Scenario: Mock HTTP request in WP-CLI
    Given that HTTP requests to https://api.github.com/repos/wp-cli/wp-cli/releases?per_page=100 will respond with:
    """
    HTTP/1.1 200
    Content-Type: application/json

    [
      {
        "url": "https://api.github.com/repos/wp-cli/wp-cli/releases/169243978",
        "assets_url": "https://api.github.com/repos/wp-cli/wp-cli/releases/169243978/assets",
        "upload_url": "https://uploads.github.com/repos/wp-cli/wp-cli/releases/169243978/assets{?name,label}",
        "html_url": "https://github.com/wp-cli/wp-cli/releases/tag/v999.9.9",
        "id": 169243978,
        "author": {
          "login": "schlessera",
          "id": 83631,
          "node_id": "MDQ6VXNlcjgzNjMx",
          "avatar_url": "https://avatars.githubusercontent.com/u/83631?v=4",
          "gravatar_id": "",
          "url": "https://api.github.com/users/schlessera",
          "html_url": "https://github.com/schlessera",
          "followers_url": "https://api.github.com/users/schlessera/followers",
          "following_url": "https://api.github.com/users/schlessera/following{/other_user}",
          "gists_url": "https://api.github.com/users/schlessera/gists{/gist_id}",
          "starred_url": "https://api.github.com/users/schlessera/starred{/owner}{/repo}",
          "subscriptions_url": "https://api.github.com/users/schlessera/subscriptions",
          "organizations_url": "https://api.github.com/users/schlessera/orgs",
          "repos_url": "https://api.github.com/users/schlessera/repos",
          "events_url": "https://api.github.com/users/schlessera/events{/privacy}",
          "received_events_url": "https://api.github.com/users/schlessera/received_events",
          "type": "User",
          "user_view_type": "public",
          "site_admin": false
        },
        "node_id": "RE_kwDOACQFs84KFnVK",
        "tag_name": "v999.9.9",
        "target_commitish": "main",
        "name": "Version 999.9.9",
        "draft": false,
        "prerelease": false,
        "created_at": "2024-08-08T03:04:55Z",
        "published_at": "2024-08-08T03:51:13Z",
        "assets": [
          {
            "url": "https://api.github.com/repos/wp-cli/wp-cli/releases/assets/184590231",
            "id": 184590231,
            "node_id": "RA_kwDOACQFs84LAJ-X",
            "name": "wp-cli-999.9.9.phar",
            "label": null,
            "content_type": "application/octet-stream",
            "state": "uploaded",
            "size": 7048108,
            "download_count": 722639,
            "created_at": "2024-08-08T03:51:05Z",
            "updated_at": "2024-08-08T03:51:08Z",
            "browser_download_url": "https://github.com/wp-cli/wp-cli/releases/download/v999.9.9/wp-cli-999.9.9.phar"
          }
        ],
        "tarball_url": "https://api.github.com/repos/wp-cli/wp-cli/tarball/v999.9.9",
        "zipball_url": "https://api.github.com/repos/wp-cli/wp-cli/zipball/v999.9.9",
        "body": "- Allow manually dispatching tests workflow [[#5965](https://github.com/wp-cli/wp-cli/pull/5965)]\r\n- Add fish shell completion [[#5954](https://github.com/wp-cli/wp-cli/pull/5954)]\r\n- Add defaults and accepted values for runcommand() options in doc [[#5953](https://github.com/wp-cli/wp-cli/pull/5953)]\r\n- Address warnings with filenames ending in fullstop on Windows [[#5951](https://github.com/wp-cli/wp-cli/pull/5951)]\r\n- Fix unit tests [[#5950](https://github.com/wp-cli/wp-cli/pull/5950)]\r\n- Update copyright year in license [[#5942](https://github.com/wp-cli/wp-cli/pull/5942)]\r\n- Fix breaking multi-line CSV values on reading [[#5939](https://github.com/wp-cli/wp-cli/pull/5939)]\r\n- Fix broken Gutenberg test [[#5938](https://github.com/wp-cli/wp-cli/pull/5938)]\r\n- Update docker runner to resolve docker path using `/usr/bin/env` [[#5936](https://github.com/wp-cli/wp-cli/pull/5936)]\r\n- Fix `inherit` path in nested directory [[#5930](https://github.com/wp-cli/wp-cli/pull/5930)]\r\n- Minor docblock improvements [[#5929](https://github.com/wp-cli/wp-cli/pull/5929)]\r\n- Add Signup fetcher [[#5926](https://github.com/wp-cli/wp-cli/pull/5926)]\r\n- Ensure the alias has the leading `@` symbol when added [[#5924](https://github.com/wp-cli/wp-cli/pull/5924)]\r\n- Include any non default hook information in CompositeCommand [[#5921](https://github.com/wp-cli/wp-cli/pull/5921)]\r\n- Correct completion case when ends in = [[#5913](https://github.com/wp-cli/wp-cli/pull/5913)]\r\n- Docs: Fixes for inline comments [[#5912](https://github.com/wp-cli/wp-cli/pull/5912)]\r\n- Update Inline comments [[#5910](https://github.com/wp-cli/wp-cli/pull/5910)]\r\n- Add a real-world example for `wp cli has-command` [[#5908](https://github.com/wp-cli/wp-cli/pull/5908)]\r\n- Fix typos [[#5901](https://github.com/wp-cli/wp-cli/pull/5901)]\r\n- Avoid PHP deprecation notices in PHP 8.1.x [[#5899](https://github.com/wp-cli/wp-cli/pull/5899)]",
        "reactions": {
          "url": "https://api.github.com/repos/wp-cli/wp-cli/releases/169243978/reactions",
          "total_count": 9,
          "+1": 4,
          "-1": 0,
          "laugh": 0,
          "hooray": 1,
          "confused": 0,
          "heart": 0,
          "rocket": 4,
          "eyes": 0
        }
      }
    ]
    """

    When I try `wp cli check-update --format=csv`
    Then STDOUT should contain:
    """
    999.9.9,major,https://github.com/wp-cli/wp-cli/releases/download/v999.9.9/wp-cli-999.9.9.phar,available
    """

  Scenario: Mock HTTP request in WordPress
    Given a WP install
    And that HTTP requests to https://api.wordpress.org/core/version-check/1.7/ will respond with:
    """
    HTTP/1.1 200
    Content-Type: application/json

    {
      "offers": [
          {
              "response": "latest",
              "download": "https:\/\/downloads.wordpress.org\/release\/wordpress-999.9.9.zip",
              "locale": "en_US",
              "packages": {
                  "full": "https:\/\/downloads.wordpress.org\/release\/wordpress-999.9.9.zip",
                  "no_content": "https:\/\/downloads.wordpress.org\/release\/wordpress-999.9.9-no-content.zip",
                  "new_bundled": "https:\/\/downloads.wordpress.org\/release\/wordpress-999.9.9-new-bundled.zip",
                  "partial": false,
                  "rollback": false
              },
              "current": "999.9.9",
              "version": "999.9.9",
              "php_version": "7.2.24",
              "mysql_version": "5.5.5",
              "new_bundled": "6.7",
              "partial_version": false
          }
      ],
      "translations": []
    }
    """

    When I run `wp core check-update`
    Then STDOUT should be a table containing rows:
      | version | update_type | package_url |
      | 999.9.9 | major       | https://downloads.wordpress.org/release/wordpress-999.9.9.zip |
