# RealEstateApp

RealEstateApp is a Xamarin.Forms mobile application with Android, iOS, and shared .NET Standard projects.

## Project structure

- `RealEstateApp.sln` - Visual Studio solution file
- `RealEstateApp/RealEstateApp` - shared Xamarin.Forms app code
- `RealEstateApp/RealEstateApp.Android` - Android app project
- `RealEstateApp/RealEstateApp.iOS` - iOS app project

## Requirements

This is a legacy Xamarin.Forms project. For best compatibility, use:

- Visual Studio 2019 or Visual Studio 2022 with Xamarin/mobile development workload installed
- Android SDK installed through Visual Studio
- A configured Android emulator or a physical Android device with USB debugging enabled
- For iOS builds: macOS with Xcode and Apple signing configured

## Run Android locally

1. Open `RealEstateApp.sln` in Visual Studio.
2. Right-click `RealEstateApp.Android` and choose **Set as Startup Project**.
3. Select an Android emulator or connected Android phone.
4. Build the solution.
5. Click **Run**.

Command-line restore/build:

```powershell
nuget restore RealEstateApp.sln
msbuild RealEstateApp\RealEstateApp.Android\RealEstateApp.Android.csproj /t:Restore,Build /p:Configuration=Debug /p:Platform=AnyCPU
```

The debug APK is normally generated under:

```text
RealEstateApp/RealEstateApp.Android/bin/Debug/
```

## GitHub Actions

The repository includes an Android build workflow at:

```text
.github/workflows/android-build.yml
```

It can be run from GitHub by opening **Actions** > **Build Xamarin Android app** > **Run workflow**.

## Notes

Xamarin.Forms is an older mobile framework. For a production-grade new version, migrate this project to .NET MAUI after confirming the existing Android build runs successfully.
