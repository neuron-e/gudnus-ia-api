# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

**Common Development:**
```bash
# Start all services (server, queue, logs, vite)
composer dev

# Run tests (config clear + test)
composer test

# Individual services
php artisan serve                    # Start development server
php artisan queue:listen --tries=1   # Queue worker
php artisan pail --timeout=0        # Real-time logs
npm run dev                         # Vite asset compilation
npm run build                       # Build assets for production
```

**Queue Management:**
```bash
php artisan horizon                  # Start Horizon (job dashboard)
php artisan queue:work               # Start queue worker
php artisan queue:failed             # View failed jobs
php artisan queue:flush              # Clear all jobs from queue
```

**Database & Migrations:**
```bash
php artisan migrate                  # Run migrations
php artisan migrate:fresh --seed     # Fresh migration with seeds
php artisan tinker                   # Laravel REPL
```

**Custom Commands:**
```bash
php artisan cleanup:expired-reports  # Clean old reports
php artisan cleanup:temp-files       # Clean temporary files
php artisan system:monitor           # System monitoring
php artisan batch:migrate-legacy     # Migrate legacy batches
php artisan batch:test-unified       # Test unified batch system
```

## Architecture Overview

This is a **Laravel-based image processing and analysis API** for electrical/electronic component analysis. The system processes images through a unified batch system with AI analysis capabilities.

### Core Architecture Components

**Unified Batch System (`app/Models/UnifiedBatch.php`):**
- Central orchestration system for all processing operations
- Supports multiple batch types: `image_processing`, `zip_processing`, `analysis`, `download_generation`, `report_generation`
- State management: `pending` → `processing` → `completed`/`failed`/`cancelled`
- Automatic progress tracking and job management

**Services:**
- `BatchManager`: Creates, starts, pauses, resumes, and cancels batches
- `StorageManager`: Handles file operations with Wasabi cloud storage
- Organized storage structure: `projects/{id}/{type}/batch_{id}/folder_{id}/`

**Job System (Laravel Horizon):**
- Master job: `ProcessBatchJob` orchestrates batch execution
- Worker jobs: `ProcessSingleImageJob`, `ProcessAnalysisChunkJob`, `GenerateDownloadChunkJob`, etc.
- Queue segmentation: `batch-control`, `atomic-images`, `analysis`, `downloads`, `reports`, `maintenance`

**Models:**
- `Project`: Top-level container for image sets
- `Folder`: Organizational structure within projects  
- `Image`: Original images with metadata
- `ProcessedImage`: Processed/cropped versions
- `ImageAnalysisResult`: AI analysis results
- `UnifiedBatch`: Tracks all processing operations

### Key Integrations

**Storage:**
- **Wasabi S3**: Primary cloud storage for images and files
- **Local Storage**: Temporary processing files
- Environment-aware paths (test/ prefix in local)

**Queue System:**
- **Laravel Horizon**: Queue monitoring and management
- **Redis**: Queue backend
- Distributed processing across multiple queue types

**AI Analysis:**
- External AI service integration for image analysis
- Chunked processing to avoid API rate limits
- Results stored as JSON in database

**APIs:**
- RESTful API structure with resource controllers
- Sanctum authentication
- Unified batch endpoints for modern operations
- Legacy compatibility endpoints during transition

### Processing Flow

1. **Upload**: Images uploaded to projects/folders via ZIP or individual files
2. **Batch Creation**: `BatchManager` creates `UnifiedBatch` with configuration
3. **Processing**: Master job dispatches workers based on batch type
4. **Execution**: Workers process items (crop, analyze, generate downloads/reports)
5. **Completion**: Automatic status updates and cleanup

### Configuration Files

**Queue Configuration (`config/queue.php`):**
- Multiple Redis connections for different queue types
- Horizon for job monitoring and auto-scaling

**Storage Configuration (`config/filesystems.php`):**
- Wasabi S3 configuration
- Local disk for temporary files

**Services:**
- Mail configuration for notifications
- External API endpoints for analysis services

## Development Guidelines

**Testing:**
- Use `composer test` which clears config before running tests
- Individual tests can be run with `php artisan test --filter=TestName`

**Queue Development:**
- Use `php artisan pail` for real-time log monitoring during development
- Monitor Horizon dashboard for job status and failures
- Always test queue jobs with `queue:listen --tries=1` to catch failures immediately

**Batch System:**
- All new processing operations should use the unified batch system
- Legacy endpoints exist for compatibility but prefer unified batch endpoints
- Always implement proper error handling and progress tracking

**Storage:**
- Use `StorageManager` service for all file operations
- Follow the organized path structure for consistency
- Clean up temporary files in job failure handlers

**API Development:**
- Follow existing controller patterns in `app/Http/Controllers/Api/`
- Use resource controllers where appropriate
- Implement proper authentication with Sanctum middleware