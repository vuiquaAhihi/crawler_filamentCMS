<?php

namespace Kho8k\Crawler\Filament\Pages;

use App\Models\Actor;
use App\Models\Category;
use App\Models\Director;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\Region;
use App\Models\Tag;
use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Components\Checkbox;
use Illuminate\Support\Str;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;


class NguonCCrawler extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cloud';
    protected static string $view = 'filament.pages.fetch-movies';
    protected static ?string $navigationGroup = 'Cào Phim';
    protected static ?string $title = 'NguonC Crawler';
    protected static ?string $navigationLabel = 'NguonC Crawler';

    public $api_url = 'https://phim.nguonc.com/api/films/phim-moi-cap-nhat';
    public $option_fetch = 'all';
    public $page_to = 1;
    public $page_from = 2;
    public $limit = 10;

    public $movies = [];
    public $movielink = [];
    public array $successMovies = [];
    public array $failedMovies = [];
    public $selectedGenre;
    public $selectedCountry;
    public $selectedType;
    public $forcecrawlMovies = false;
    public $resizeImage = true;
    public $thumbwidth = 1280;
    public $thumbheight = 720;
    public $posterwidth = 720;
    public $posterheight = 1280;

    public function fetchMovies(): void
    {
        if ($this->option_fetch === 'all') {

            $this->fetchAllMovies();
        } elseif ($this->option_fetch === 'single') {

            $this->fetchSingleMovies();
        }
    }

    public function crawlMovies(): void
    {
        $this->fetchMovieDetails();
    }

    private function fetchSingleMovies()
    {
        if (!$this->api_url) {
            Notification::make()
                ->title('Lỗi')
                ->body("Vui lòng nhập API URL!")
                ->danger()
                ->send();
            return;
        }

        $urls = array_filter(array_map('trim', explode("\n", $this->api_url)));

        if (empty($urls)) {
            Notification::make()
                ->title('Lỗi')
                ->body("Danh sách URL phim đơn không hợp lệ!")
                ->danger()
                ->send();
            return;
        }

        $this->movies = [];
        $responses = Http::pool(
            fn($pool) =>
            array_map(fn($url) => $pool->get($url), $urls)
        );

        foreach ($responses as $index => $response) {
            if ($response->successful()) {
                $data = $response->json()['movie'] ?? null;
                if ($data) {
                    $this->movies[] = $data;
                    $this->movielink[] = $urls[$index];
                }
            } else {
                Notification::make()
                    ->title('Lỗi')
                    ->body("Không thể lấy dữ liệu từ URL: " . $urls[$index])
                    ->danger()
                    ->send();
            }
        }

        $this->movies = array_values($this->movies);
    }

    private function fetchAllMovies()
    {
        if (!$this->api_url) {
            Notification::make()
                ->title('Lỗi')
                ->body("Vui lòng nhập API URL!")
                ->danger()
                ->send();
            return;
        }

        $this->movies = [];
        $responses = Http::pool(
            fn($pool) =>
            array_map(
                fn($page) => $pool->get($this->api_url, [
                    'page' => $page,
                    'limit' => $this->limit,
                ]),
                range($this->page_to, $this->page_from)
            )
        );
        foreach ($responses as $index => $response) {
            if ($response->successful()) {
                $data = $response->json()['items'] ?? [];
                $this->movies = array_merge($this->movies, $data);
                $slugs = array_map(fn($slug) => "https://phim.nguonc.com/api/film/{$slug}", array_column($data, 'slug'));
                $this->movielink = array_merge($this->movielink, array_filter($slugs));
            } else {
                Notification::make()
                    ->title('Lỗi')
                    ->body("Không thể lấy dữ liệu từ API tại trang " . ($index + 1) . "!")
                    ->danger()
                    ->send();
            }
        }
        $this->movies = array_values($this->movies);
    }


    private function fetchMovieDetails(): void
    {
        $this->successMovies = [];
        $this->failedMovies = [];

        if (empty($this->movies)) {
            Notification::make()
                ->title('Lỗi')
                ->body('Không có phim nào để cào!')
                ->danger()
                ->send();
            return;
        }

        $movieUrls = array_map(fn($movie) => "https://phim.nguonc.com/api/film/{$movie['slug']}", $this->movies);

        $responses = Http::pool(
            fn($pool) =>
            array_map(fn($url) => $pool->get($url), $movieUrls)
        );

        foreach ($responses as $index => $movieResponse) {
            $movie = $this->movies[$index] ?? null;

            if ($movieResponse->successful() && $movie) {
                $movieDetails = $movieResponse->json()['movie'] ?? null;

                if (!$movieDetails) {
                    $this->failedMovies[] = $movie['name'] ?? 'Không có tên';
                    continue;
                }

                if ($this->forcecrawlMovies) {
                    $this->proccessMovieDetail($movieDetails);
                } else {
                    $movieModel = Movie::where('update_identity', $movieDetails['id'])->first();
                    if ($movieModel) {
                        $this->syncEpisode($movieDetails['episodes'] ?? [], $movieModel);
                    } else {
                        $this->proccessMovieDetail($movieDetails);
                    }
                }
                $this->successMovies[] = "https://phim.nguonc.com/api/film/" . $movieDetails['slug'];
            } else {
                $this->failedMovies[] = "https://phim.nguonc.com/api/film/" . $movie['slug'] ?? 'Không có tên';
            }
        }

        Notification::make()
            ->title('Thành công')
            ->body('Cào phim hoàn tất!')
            ->success()
            ->send();
    }

    private function proccessMovieDetail($movieDetails)
    {
        $categories = $movieDetails['category'] ?? [];
        $movieGenres = [];
        $movieCountry = null;
        $movieType = null;
        $movieActor = array_map('trim', explode(',', $movieDetails['casts'] ?? ''));
        $movieDirector = array_map('trim', explode(',', $movieDetails['director'] ?? ''));
        $movieYear = '';
        foreach ($categories as $group) {
            if ($group['group']['name'] === "Thể loại") {
                $movieGenres = array_column($group['list'], 'name');
            }
            if ($group['group']['name'] === "Quốc gia") {
                $movieCountry = $group['list'][0]['name'] ?? null;
            }
            if ($group['group']['name'] === "Định dạng") {
                $movieType = $group['list'][0]['name'] ?? null;
            }
            if ($group['group']['name'] === "Năm") {
                $movieYear = $group['list'][0]['name'] ?? null;
            }
        }
        if (
            (!empty($this->selectedGenre) && !array_intersect($this->selectedGenre, $movieGenres)) ||
            (!empty($this->selectedCountry) && !in_array($movieCountry, $this->selectedCountry)) ||
            (!empty($this->selectedType) && !in_array($movieType, $this->selectedType))
        ) {
            $this->failedMovies[] = $movie['name'] ?? 'Không có tên';
        }

        $movieModel = Movie::updateOrCreate(
            ['update_identity' => $movieDetails['id']],
            [
                'name' => $movieDetails['name'],
                'origin_name' => $movieDetails['original_name'],
                'slug' => $movieDetails['slug'],
                'content' => $movieDetails['description'] ?? '',
                'thumb_url' => $this->saveImage($movieDetails['poster_url'] ?? null, $movieDetails['slug'] . '_thumb' ,$this->thumbwidth, $this->thumbheight),
                'poster_url' => $this->saveImage($movieDetails['thumb_url'] ?? null, $movieDetails['slug'] . '_poster' ,$this->posterwidth, $this->posterheight),
                'type' => $movieType === 'Phim bộ' ? 'series' : ($movieType === 'Phim lẻ' ? 'single' : 'unknown'),
                'status' => isset($movieDetails['current_episode']) && Str::contains($movieDetails['current_episode'], 'Hoàn tất')
                    ? 'completed'
                    : ($movieType === 'Phim bộ' ? 'ongoing' : 'completed'),
                'episodes_time' => $movieDetails['time'] ?? '',
                'publish_year' => $movieYear ?? null,
                'episodes_current' => $movieDetails['current_episode'] ?? '',
                'episodes_total' => $movieDetails['total_episodes'] ?? '',
                'quality' => $movieDetails['quality'] ?? 'FHD',
                'languages' => $movieDetails['language'] ?? 'VietSub',
                'update_handler' => static::class,
                'update_identity' => $movieDetails['id'],
                'update_checksum' => '',
                'user_id' => auth()->id() ?? 1,
                'user_name' => auth()->user()->name ?? 'admin',
            ]
        );

        $this->syncCategories($movieGenres ?? [], $movieModel);
        $this->syncActor($movieActor ?? [], $movieModel);
        $this->syncRegion($movieCountry ?? [], $movieModel);
        $this->syncEpisode($movieDetails['episodes'] ?? [], $movieModel);
        $this->syncDirector($movieDirector ?? [], $movieModel);
        $this->syncTag($movieDetails, $movieModel);
    }

    private function syncActor($actors, $movie)
    {
        if (is_string($actors)) {
            $actors = [$actors];
        }
        if (!is_array($actors) || empty($actors)) {
            return;
        }
        $actorIds = [];
        foreach ($actors as $actor) {
            if (!is_string($actor) || empty(trim($actor))) {
                continue;
            }
            $actorModel = Actor::firstOrCreate(
                ['name' => $actor],
                [
                    'name_md5' => md5($actor),
                    'slug' => Str::slug($actor),
                    'seo_title' => null,
                    'seo_des' => null,
                    'seo_key' => null,
                    'user_id' => auth()->id() ?? 1,
                    'user_name' => auth()->user()->name ?? 'admin',
                ]
            );
            $actorIds[] = $actorModel->id;
        }

        $movie->actors()->syncWithoutDetaching($actorIds);
    }

    private function syncDirector($directors, $movie)
    {
        if (is_string($directors)) {
            $directors = [$directors];
        }
        if (!is_array($directors) || empty($directors)) {
            return;
        }
        $directorIds = [];
        foreach ($directors as $director) {
            if (!is_string($director) || empty(trim($director))) {
                continue;
            }
            $directorModel = Director::firstOrCreate(
                ['name' => $director],
                [
                    'name_md5' => md5($director),
                    'slug' => Str::slug($director),
                    'seo_title' => null,
                    'seo_des' => null,
                    'seo_key' => null,
                    'user_id' => auth()->id() ?? 1,
                    'user_name' => auth()->user()->name ?? 'admin',
                ]
            );
            $directorIds[] = $directorModel->id;
        }

        $movie->directors()->syncWithoutDetaching($directorIds);
    }

    private function syncRegion($regionData, $movie)
    {
        if (is_string($regionData)) {
            $regionData = [
                'id' => null,
                'name' => $regionData,
                'slug' => Str::slug($regionData),
            ];
        }

        if (!isset($regionData['id'], $regionData['name'], $regionData['slug'])) {
            return;
        }

        $region = Region::firstOrCreate(
            ['slug' => $regionData['slug']],
            [
                'name' => $regionData['name'],
                'seo_title' => null,
                'seo_des' => null,
                'seo_key' => null,
                'user_id' => auth()->id() ?? 1,
                'user_name' => auth()->user()->name ?? 'admin',
            ]
        );

        $movie->regions()->syncWithoutDetaching([$region->id]);
    }

    private function syncEpisode(array $episodes = [], $movie)
    {
        foreach ($episodes as $server) {
            if (!isset($server['server_name']) || !isset($server['items'])) {
                continue;
            }
            foreach ($server['items'] as $episode) {
                if (!isset($episode['name'], $episode['slug'], $episode['m3u8'])) {
                    continue;
                }
                Episode::updateOrCreate(
                    [
                        'movie_id' => $movie->id,
                        'slug' => $episode['slug'],
                    ],
                    [
                        'server' => $server['server_name'],
                        'name' => $episode['name'],
                        'slug' => $episode['slug'],
                        'type' => 'm3u8',
                        'link' => $episode['m3u8'],
                    ]
                );
            }
        }
    }

    private function syncCategories(array $categories, $movie)
    {
        $categoryIds = [];

        foreach ($categories as $category) {
            if (!isset($category['name']) || !isset($category['slug'])) {
                continue;
            }
            $categoryModel = Category::firstOrCreate(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'seo_title' => '',
                    'seo_des' => '',
                    'seo_key' => '',
                    'user_id' => auth()->id() ?? 1,
                    'user_name' => auth()->user()->name ?? 'admin',
                ]
            );

            $categoryIds[] = $categoryModel->id;
        }
        $movie->categories()->sync($categoryIds);
    }

    private function syncTag($movieDetails, $movie)
    {
        $tags = [];

        if (!empty($movieDetails['name'])) {
            $tags[] = trim($movieDetails['name']);
        }
        if (!empty($movieDetails['origin_name'])) {
            $tags[] = trim($movieDetails['origin_name']);
        }
        $tags = array_unique($tags);

        $tagIds = [];
        foreach ($tags as $tagName) {
            $slug = Str::slug($tagName);
            $nameMd5 = md5($tagName);
            $tagModel = Tag::firstOrCreate(
                ['name_md5' => $nameMd5],
                [
                    'name' => $tagName,
                    'slug' => $slug,
                    'SEO_title' => null,
                    'SEO_des' => null,
                    'SEO_key' => null,
                    'user_id' => auth()->id() ?? 1,
                    'user_name' => auth()->user()->name ?? 'admin',
                ]
            );
            $tagIds[] = $tagModel->id;
        }
        $movie->tags()->sync($tagIds);
    }

    private function saveImage(?string $imageUrl, ?string $slug = null, ?int $width = null, ?int $height = null): ?string
    {
        if (!$imageUrl) {
            return null;
        }

        try {
            $imageContent = $this->fetchImageContent($imageUrl);
            if (!$imageContent) {
                throw new \Exception("Không thể tải ảnh từ URL: {$imageUrl}");
            }

            static $manager = null;
            if (!$manager) {
                $manager = new ImageManager(new Driver());
            }

            $image = $manager->read($imageContent);
            if ($this->resizeImage &&  $width || $height) {
                $image = $image->scale(width: $width, height: $height);
            }

            $filename = ($slug ? Str::slug($slug) : pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME)) . '.webp';
            $filePath = 'public/images/' . $filename;
            Storage::put($filePath, $image->toWebp(80)->toString());

            return asset('storage/images/' . $filename);
        } catch (\Exception $e) {
            Log::error("Lỗi lưu ảnh: " . $e->getMessage());
            return null;
        }
    }

    private function fetchImageContent(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $data = curl_exec($ch);
        curl_close($ch);

        return $data ?: null;
    }

    protected function getFormSchema(): array
    {
        return [
            Tabs::make('Settings')
                ->tabs([
                    Tabs\Tab::make('Cài đặt API')
                        ->schema([
                            Textarea::make('api_url')
                                ->label('API URL')
                                ->placeholder('Nhập URL API...')
                                ->rows(10)
                                ->required(),
                            Fieldset::make('Options')
                                ->columns(4)
                                ->schema([
                                    Radio::make('option_fetch')
                                        ->options([
                                            'all' => 'Cào tất cả theo API',
                                            'single' => 'Cào từng phim lẻ',
                                        ])
                                        ->default('all')
                                        ->label('Chế độ cào dữ liệu'),
                                    TextInput::make('page_to')
                                        ->label('Từ trang')
                                        ->numeric()
                                        ->default(1)
                                        ->placeholder('Nhập số trang cào...'),
                                    TextInput::make('page_from')
                                        ->label('Đến trang')
                                        ->numeric()
                                        ->default(2)
                                        ->placeholder('Nhập số trang cào...'),
                                    TextInput::make('limit')
                                        ->label('Số Lượng trên trang')
                                        ->numeric()
                                        ->default(10)
                                        ->placeholder('Nhập số lượng trên 1 trang...'),
                                ]),
                            Fieldset::make('Image')
                                ->schema([
                                    Checkbox::make('resizeImage')
                                        ->label('Tự động resize ảnh'),
                                    Fieldset::make('Thumbnail-(Kích thước chuẩn 16-9)')
                                        ->columns(2)
                                        ->schema([
                                            TextInput::make('thumbwidth')
                                                ->label('Chiều rộng')
                                                ->numeric()
                                                ->placeholder('Nhập chiều rộng...'),
                                            TextInput::make('thumbheight')
                                                ->label('Chiều cao')
                                                ->numeric()
                                                ->placeholder('Nhập chiều cao...'),
                                        ]),
                                    Fieldset::make('Poster-(Kích thước chuẩn 9-16)')
                                        ->schema([
                                            TextInput::make('posterwidth')
                                                ->label('Chiều rộng')
                                                ->numeric()
                                                ->placeholder('Nhập chiều rộng...'),
                                            TextInput::make('posterheight')
                                                ->label('Chiều cao')
                                                ->numeric()
                                                ->placeholder('Nhập chiều cao...'),
                                        ])
                                ]),
                            Checkbox::make('forcecrawlMovies')
                                ->label('Bắt buộc câp nhật thông tin phim')
                                ->default(false),
                        ]),
                    Tabs\Tab::make('Bộ lọc')
                        ->schema([
                            Fieldset::make('Filters')
                                ->label('Bộ lọc')
                                ->columns(3)
                                ->schema([
                                    Select::make('selectedGenre')
                                        ->multiple()
                                        ->label('Thể loại')
                                        ->options([
                                            'action' => 'Hành động',
                                            'drama' => 'Tâm lý',
                                            'comedy' => 'Hài',
                                        ])
                                        ->reactive(),

                                    Select::make('selectedCountry')
                                        ->multiple()
                                        ->label('Quốc gia')
                                        ->options([
                                            'us' => 'Mỹ',
                                            'kr' => 'Hàn Quốc',
                                            'vn' => 'Việt Nam',
                                            'jp' => 'Nhật Bản',
                                        ])
                                        ->reactive(),

                                    Select::make('selectedType')
                                        ->multiple()
                                        ->label('Loại')
                                        ->options([
                                            'single' => 'Phim lẻ',
                                            'series' => 'Phim bộ',
                                        ])
                                        ->reactive(),
                                ]),
                        ]),
                ]),
        ];
    }
}
