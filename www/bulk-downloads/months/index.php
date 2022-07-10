<?
require_once('Core.php');

use function Safe\apcu_fetch;

$canDownload = false;

if($GLOBALS['User'] !== null && $GLOBALS['User']->Benefits->CanBulkDownload){
	$canDownload = true;
}

$years = [];

try{
	$years = apcu_fetch('bulk-downloads-years');
}
catch(Safe\Exceptions\ApcuException $ex){
	$result = Library::RebuildBulkDownloadsCache();
	$years = $result['years'];
}

?><?= Template::Header(['title' => 'Downloads by Month', 'highlight' => '', 'description' => 'Download zip files containing all of the Standard Ebooks released in a given month.']) ?>
<main>
	<section class="bulk-downloads">
		<h1>Downloads by Month</h1>
		<? if(!$canDownload){ ?>
			<p><a href="/about#patrons-circle">Patrons circle members</a> can download zip files containing all of the ebooks that were released in a given month of Standard Ebooks history. You can <a href="/donate#patrons-circle">join the Patrons Circle</a> with a small donation in support of our continuing mission to create free, beautiful digital literature.</p>
		<? } ?>
		<p>These zip files contain each ebook in every format we offer, and are updated once daily with the latest versions of each ebook. Read about <a href="/help/how-to-use-our-ebooks#which-file-to-download">which file format to download</a>.</p>
		<table class="download-list">
			<tbody>
		<? foreach($years as $year => $months){
			$yearHeader = Formatter::ToPlainText($year);
		 ?>
			<tr class="year-header">
				<th colspan="13" scope="colgroup" id="<?= $yearHeader ?>"><?= Formatter::ToPlainText((string)$year) ?></th>
			</tr>
			<tr class="mid-header">
				<th id="<?= $yearHeader?>-type" scope="col">Month</th>
				<th id="<?= $yearHeader ?>-ebooks" scope="col">Ebooks</th>
				<th id="<?= $yearHeader ?>-updated" scope="col">Updated</th>
				<th id="<?= $yearHeader ?>-download" colspan="10" scope="col">Ebook format</th>
			</tr>

			<? foreach($months as $month => $collection){
				$monthHeader = Formatter::ToPlainText($month);
			?>
			<tr>
				<th class="row-header" headers="<?= $yearHeader ?> <?= $monthHeader ?> <?= $yearHeader ?>-type" id="<?= $monthHeader ?>"><?= Formatter::ToPlainText($month) ?></th>
				<td class="number" headers="<?= $yearHeader ?> <?= $monthHeader ?> <?= $yearHeader ?>-ebooks"><?= Formatter::ToPlainText(number_format($collection->EbookCount)) ?></td>
				<td class="number" headers="<?= $yearHeader ?> <?= $monthHeader ?> <?= $yearHeader ?>-updated"><?= Formatter::ToPlainText($collection->UpdatedString) ?></td>

				<? foreach($collection->ZipFiles as $item){ ?>
					<td headers="<?= $yearHeader ?> <?= $monthHeader ?> <?= $yearHeader ?>-download" class="download"><a href="<?= $item->Url ?>"><?= $item->Type ?></a></td>
					<td headers="<?= $yearHeader ?> <?= $monthHeader ?> <?= $yearHeader ?>-download">(<?= Formatter::ToPlainText($item->Size) ?>)</td>
				<? } ?>
			</tr>
			<? } ?>

		<? } ?>
			</tbody>
		</table>
	</section>
</main>
<?= Template::Footer() ?>