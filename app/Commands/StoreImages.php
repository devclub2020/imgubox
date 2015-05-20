<?php namespace ImguBox\Commands;

use ImguBox\Commands\Command;
use ImguBox\User;
use ImguBox\Log;

use ImguBox\Services\ImgurService;
use ImguBox\Services\DropboxService;
use ImguBox\Services\ImguBoxService;

use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Container\Container;

use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldBeQueued;

use Carbon\Carbon;
use Cache, App;

class StoreImages extends Command implements SelfHandling, ShouldBeQueued {

	use InteractsWithQueue, SerializesModels;

	protected $user;

	protected $favorite;

	protected $imgurToken;

	protected $dropboxToken;

	protected $imgur;

	protected $dropbox;

	protected $imgubox;

	/**
	 * Create a new command instance.
	 *
	 * @return void
	 */
	public function __construct($userId, $favoriteId)
	{
		$this->user     = User::findOrFail($userId);
		$this->favorite = Cache::get("user:{$userId}:favorite:{$favoriteId}");
	}


	/**
	 * Execute the command.
	 *
	 * @return void
	 */
	public function handle(Container $app)
	{
		$this->imgurToken   = $this->user->imgurToken;
		$this->dropboxToken = $this->user->dropboxToken;

		$imgur   = App::make('ImguBox\Services\ImgurService');
		$imgur->setUser($this->user);
		$imgur->setToken($this->imgurToken);

		$this->imgur = $imgur;

		$dropbox = App::make('ImguBox\Services\DropboxService');
		$dropbox->setToken($this->dropboxToken);

		$this->dropbox = $dropbox;

		if ($this->favorite->is_album === false) {

			$image       = $imgur->image($this->favorite->id);

			// If no error accoured, proceed
			if (!property_exists($image, 'error')) {

				$folderName = $this->getFoldername($image);

				$this->storeImage($folderName, $image);

			}
			else {

				// Handle Error here

			}

		}
		else {

			// Handle Album
			$this->storeAlbum();

		}

	}

	/**
	 * Store an Album
	 * @return void
	 */
	private function storeAlbum()
	{
		$album      = $this->imgur->gallery($this->favorite->id);
		$folderName = $this->getFoldername($album);

		$this->dropbox->createFolder("/$folderName");

		$this->storeDescription($folderName, $album);

		foreach($album->images as $image) {

			$this->storeImage($folderName, $image);
			$this->storeDescription($folderName, $image);

		}

	}

	/**
	 * Store Image description to Cloud Storage
	 * @param  string $folderName
	 * @param  object $image
	 * @return void
	 */
	private function storeDescription($folderName, $image)
	{
		if (property_exists($image, 'description')) {

			if (!empty($image->description)) {

				$this->dropbox->uploadDescription("/$folderName/{$image->id} - description.txt", $image->description);

			}

		}
	}

	/**
	 * Store an Image
	 * @param  string $folderName
	 * @param  object $image
	 * @return void
	 */
	private function storeImage($folderName, $image)
	{
		$this->storeDescription($folderName, $image);

		$filename    = pathinfo($image->link, PATHINFO_BASENAME);
		$this->dropbox->uploadFile("/$folderName/$filename", fopen($image->link,'rb'));

		$this->storeGifs($image, $folderName, $filename);

		Log::create([
			'user_id'  => $this->user->id,
			'imgur_id' => $image->id,
			'is_album' => false
		]);

	}

	private function storeGifs($image, $folderName, $filename)
	{
		if ($image->animated === true) {

			// GIFV
			$filename    = pathinfo($image->gifv, PATHINFO_BASENAME);
			$this->dropbox->uploadFile("/$folderName/$filename", fopen($image->gifv,'rb'));

			// WEBM
			$filename    = pathinfo($image->webm, PATHINFO_BASENAME);
			$this->dropbox->uploadFile("/$folderName/$filename", fopen($image->webm,'rb'));

			// MP4
			if (property_exists($image, 'mp4')) {
				$filename    = pathinfo($image->mp4, PATHINFO_BASENAME);
				$this->dropbox->uploadFile("/$folderName/$filename", fopen($image->mp4,'rb'));
			}

		}
	}

	/**
	 * Build foldername for imgur object
	 * @param  mixed  $object
	 * @return string
	 */
	private function getFoldername($object)
	{
		if (is_null($object->title)) {

			return $object->id;

		}

		return str_slug("{$object->title} {$object->id}");
	}

}
