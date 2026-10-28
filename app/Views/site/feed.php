<?= '<?xml version="1.0" encoding="UTF-8"?>' . "\n" ?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title><?= esc($settings['site_name']) ?></title>
    <atom:link href="<?= esc($home . 'feed/') ?>" rel="self" type="application/rss+xml" />
    <link><?= esc($home) ?></link>
    <description><?= esc($settings['tagline']) ?></description>
    <language>en-US</language>
<?php if ($items !== []): ?>
    <lastBuildDate><?= $items[0]['date'] ?></lastBuildDate>
<?php endif ?>
<?php foreach ($items as $item): ?>
    <item>
        <title><?= esc($item['title']) ?></title>
        <link><?= esc($item['url']) ?></link>
        <guid isPermaLink="true"><?= esc($item['url']) ?></guid>
        <pubDate><?= $item['date'] ?></pubDate>
<?php foreach ($item['categories'] as $category): ?>
        <category><?= esc($category) ?></category>
<?php endforeach ?>
        <description><?= esc($item['description']) ?></description>
        <content:encoded><![CDATA[<?= str_replace(']]>', ']]]]><![CDATA[>', $item['content']) ?>]]></content:encoded>
    </item>
<?php endforeach ?>
</channel>
</rss>
