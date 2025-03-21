@extends('kho8k-crawler::filament.pages.fetch-movies')

<x-filament-panels::page>
    <div class="p-6">
        <div>
            <form wire:submit.prevent="fetchMovies">
                {{ $this->form }}
                <x-filament::button type="submit" class="mt-6">
                    Lấy danh sách phim
                </x-filament::button>
            </form>
            <div class="mt-6">
                <div class="grid grid-cols-1 md:grid-cols-2">
                    <h2 class="text-lg font-bold">Danh sách tất cả phim</h2>
                    <x-filament::button type="button" wire:click="crawlMovies" class="mb-2 ml-auto ">
                        Cào phim
                    </x-filament::button>
                </div>
                <textarea class="w-full p-3 border rounded" style="height: 12rem;">{{ implode(
                    "\n",
                    array_map(
                        fn($movielink) => ($movielink ?? 'Không có tên'),
                        $movielink,
                    ),
                ) ?:
                    'Chưa có dữ liệu phim.' }}</textarea>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                <div>
                    <h2 class="text-lg font-bold">Danh sách phim thành công</h2>
                    <textarea class="w-full p-3 border rounded" style="height: 12rem;" readonly>@php
                        echo implode("\n", $successMovies ?? []);
                    @endphp</textarea>
                </div>
                <div>
                    <h2 class="text-lg font-bold">Danh sách phim thất bại</h2>
                    <textarea class="w-full p-3 border rounded" style="height: 12rem;" readonly>@php
                        echo implode("\n", $failedMovies ?? []);
                    @endphp</textarea>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>


