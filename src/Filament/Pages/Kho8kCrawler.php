<?php

namespace Kho8k\Crawler\Filament\Pages;

use App\Models\Actor;
use App\Models\Category;
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
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Symfony\Component\Console\Input\Input;

class Kho8kCrawler extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cloud';
    protected static string $view = 'filament.pages.fetch-movies';
    protected static ?string $navigationGroup = 'Cào Phim';
    protected static ?string $title = 'Kho 8K Crawler';
    protected static ?string $navigationLabel = 'Kho 8K Crawler';

    public $api_url = 'https://travelnow.us.com/api/teamall/phimxxx/moi-cap-nhat';
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
        match ($this->option_fetch) {
            'all' => $this->fetchAllMovies(),
            'single' => $this->fetchSingleMovies(),
            default => Notification::make()
                ->title('Lỗi')
                ->body('Tùy chọn không hợp lệ!')
                ->danger()
                ->send(),
        };
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
                ->body("Danh sách URL không hợp lệ!")
                ->danger()
                ->send();
            return;
        }

        $this->movielink = [];
        $this->movies = [];
        $responses = Http::pool(
            fn($pool) =>
            array_map(fn($url) => $pool->get($url), $urls)
        );

        foreach ($responses as $index => $response) {
            if ($response->successful()) {
                $data = $response->json()['data'] ?? [];
                $filteredMovies = array_filter($data, function ($movie) {
                    $movieGenres = isset($movie['categories']['name']) && is_array($movie['categories']['name'])
                        ? $movie['categories']['name']
                        : [];
                    $movieCountry = trim($movie['country'] ?? '');
                    return !(
                        (isset($this->selectedGenre) && array_intersect($movieGenres, $this->selectedGenre)) ||
                        (isset($this->selectedCountry) && in_array($movieCountry, $this->selectedCountry)) ||
                        (isset($movie['type']) && in_array($movie['type'], $this->selectedType ?? []))
                    );
                });

                $this->movies = array_merge($this->movies, $filteredMovies);
                $slugs = array_map(fn($slug) => "https://travelnow.us.com/api/teamall/phimxxx/search/{$slug}", array_column($filteredMovies, 'slug'));
                $this->movielink = array_merge($this->movielink, array_filter($slugs));
            } else {
                Notification::make()
                    ->title('Lỗi')
                    ->body("Không thể lấy dữ liệu từ URL: " . ($urls[$index] ?? 'Không xác định') . "!")
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

        $this->movielink = [];
        $this->movies = [];
        $responses = Http::pool(
            fn($pool) =>
            array_map(
                fn($page) => $pool->get($this->api_url, [
                    'categories' => implode(',', $this->selectedGenre ?? []),
                    'country' => implode(',', $this->selectedCountry ?? []),
                    'type' => implode(',', $this->selectedType ?? []),
                    'page' => $page,
                    'limit' => $this->limit,
                ]),
                range($this->page_to, $this->page_from)
            )
        );

        foreach ($responses as $index => $response) {
            if ($response->successful()) {
                $data = $response->json()['data'] ?? [];
                $filteredMovies = array_filter($data, function ($movie) {
                    $movieGenres = isset($movie['categories']['name']) && is_array($movie['categories']['name'])
                        ? $movie['categories']['name']
                        : [];
                    $movieCountry = trim($movie['country'] ?? '');
                    return !(
                        (isset($this->selectedGenre) && array_intersect($movieGenres, $this->selectedGenre)) ||
                        (isset($this->selectedCountry) && in_array($movieCountry, $this->selectedCountry)) ||
                        (isset($movie['type']) && in_array($movie['type'], $this->selectedType ?? []))
                    );
                });
                $this->movies = array_merge($this->movies, $filteredMovies);
                $slugs = array_map(fn($slug) => "https://travelnow.us.com/api/teamall/phimxxx/search/{$slug}", array_column($filteredMovies, 'slug'));
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

        $movieUrls = array_map(fn($movie) => "https://travelnow.us.com/api/teamall/phimxxx/search/{$movie['slug']}", $this->movies);

        $responses = Http::pool(
            fn($pool) =>
            array_map(fn($url) => $pool->get($url), $movieUrls)
        );

        foreach ($responses as $index => $movieResponse) {
            $this->processMovieResponse($movieResponse, $index);
        }

        Notification::make()
            ->title('Thành công')
            ->body('Cào phim hoàn tất!')
            ->success()
            ->send();
    }

    private function processMovieResponse($movieResponse, $index): void
    {
        $movie = $this->movies[$index] ?? null;

        if ($movieResponse->successful() && $movie) {
            $movieDetails = $movieResponse->json()['data'][0] ?? null;

            if (!$movieDetails) {
                $this->failedMovies[] = $movie['name'] ?? 'Không có tên';
                return;
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

            $this->successMovies[] = 'https://travelnow.us.com/api/teamall/phimxxx/search/' . $movieDetails['slug'];
        } else {
            $this->failedMovies[] = 'https://travelnow.us.com/api/teamall/phimxxx/search/' . $movie['slug'] ?? 'Không có tên';
        }
    }

    private function proccessMovieDetail($movieDetails)
    {
        $movieModel = Movie::updateOrCreate(
            ['update_identity' => $movieDetails['id']],
            [
                'name' => $movieDetails['name'],
                'origin_name' => $movieDetails['name'],
                'slug' => $movieDetails['slug'],
                'content' => $movieDetails['description'] ?? '',
                'thumb_url' => $this->saveImage($movieDetails['thumb'] ?? null, $movieDetails['slug'] . '_thumb', $this->thumbwidth, $this->thumbheight),
                'poster_url' => $this->saveImage($movieDetails['thumb'] ?? null, $movieDetails['slug'] . '_poster', $this->posterwidth, $this->posterheight),
                'type' => isset($movieDetails['type']) && in_array($movieDetails['type'], ['single', 'series'])
                    ? $movieDetails['type']
                    : 'single',
                'status' => isset($movieDetails['status']) && in_array($movieDetails['status'], ['trailer', 'ongoing', 'completed'])
                    ? $movieDetails['status']
                    : 'completed',
                'episodes_time' => $movieDetails['time'] ?? '',
                'publish_year' => isset($movieDetails['year']) ? (int)$movieDetails['year'] : null,
                'episodes_current' => count($movieDetails['episodes'] ?? []),
                'episodes_total' => count($movieDetails['episodes'] ?? []),
                'quality' => $movieDetails['quality'] ?? 'FHD',
                'languages' => $movieDetails['languages'] ?? 'VietSub',
                'update_handler' => static::class,
                'update_identity' => $movieDetails['id'],
                'update_checksum' => '',
                'user_id' => auth()->id() ?? 1,
                'user_name' => auth()->user()->name ?? 'admin',
            ]
        );

        $this->syncCategories($movieDetails['categories'] ?? [], $movieModel);
        $this->syncActor($movieDetails['actors'] ?? [], $movieModel);
        $this->syncRegion($movieDetails['country'] ?? [], $movieModel);
        $this->syncEpisode($movieDetails['episodes'] ?? [], $movieModel);
        $this->syncTag($movieDetails, $movieModel);
    }

    private function syncActor(array $actors = [], $movie)
    {
        $actorIds = [];
        foreach ($actors as $actor) {
            $actorModel = Actor::firstOrCreate(['name' => $actor]);
            $actorIds[] = $actorModel->id;
        }
        $movie->actors()->sync($actorIds);
    }

    private function syncRegion(string $regions = '', $movie)
    {
        $region = Region::firstOrCreate(
            ['name' => $regions],
            [
                'slug' => Str::slug($regions),
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
        foreach ($episodes as $index => $episodeUrl) {
            Episode::updateOrCreate(
                ['movie_id' => $movie->id],
                [
                    'server' => 'Vip',
                    'name' => 'Full HD',
                    'slug' => 'full-hd',
                    'type' => 'single',
                    'link' => $episodeUrl

                ]
            );
        }
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

    private function syncCategories(array $categories = [], $movie)
    {
        $categoryIds = [];
        foreach ($categories as $category) {
            if (is_array($category)) {
                $categoryName = $category['name'] ?? null;
            } else {
                $categoryName = $category;
            }
            if (empty($categoryName)) {
                continue;
            }
            $categoryModel = Category::firstOrCreate(['name' => $categoryName]);
            $categoryIds[] = $categoryModel->id;
        }

        $movie->categories()->sync($categoryIds);
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
                                ->required()
                                ->rows(10),
                            Fieldset::make('Cài đặt')
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
                                        ->label('Số lượng phim trên trang')
                                        ->numeric()
                                        ->default(10)
                                        ->placeholder('Nhập số lượng trên 1 trang...'),
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
                    Tabs\Tab::make('Cài đặt hình ảnh')
                        ->schema([
                            Fieldset::make('Hình ảnh')
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
                        ]),
                ]),
        ];
    }
}



/*
'server' => $episode['server'] ?? 'Vip',
'name' => $episode['name'] ?? 'Full HD',
'slug' => $episode['slug'] ?? 'full-hd',
'type' => $episode['type'] ?? 'M3U8',
'link' => $episode['link'] ?? ''
*/
