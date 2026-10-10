<?php

namespace Database\Seeders;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\ProjectType;
use App\Models\Skill;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TaxonomySeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const SKILLS = [
        'Research' => ['Market Research', 'Competitor Analysis', 'Survey Design', 'Desk Research', 'Customer Interviews'],
        'Marketing' => ['SEO', 'Performance Marketing', 'Email Marketing', 'Brand Strategy', 'Social Media Marketing'],
        'Content' => ['Copywriting', 'Technical Writing', 'Editing and Proofreading', 'Content Strategy', 'Video Scripting'],
        'Design' => ['UI Design', 'UX Research', 'Graphic Design', 'Presentation Design', 'Brand Identity'],
        'Data & Analytics' => ['Excel Modelling', 'SQL', 'Power BI', 'Tableau', 'Python for Data', 'Data Cleaning'],
        'Technology' => ['Laravel', 'React', 'WordPress', 'Mobile App Development', 'API Integration', 'QA Testing'],
        'Operations' => ['Process Mapping', 'Project Management', 'Vendor Management', 'Zoho One Setup'],
        'Finance' => ['Financial Modelling', 'Bookkeeping', 'GST Compliance', 'Budgeting and Forecasting'],
        'Sales' => ['Lead Generation', 'CRM Administration', 'Sales Enablement', 'Proposal Writing'],
    ];

    /** @var list<string> */
    private const INDUSTRIES = [
        'Manufacturing', 'Retail and E-commerce', 'Financial Services', 'Healthcare', 'Education',
        'Logistics', 'Real Estate', 'Professional Services', 'Technology', 'Hospitality',
    ];

    /** @var list<string> */
    private const PROJECT_TYPES = [
        'Market Research',
        'Dashboard/Reporting',
        'Content & Social',
        'Process Automation',
        'Website/App Build',
        'Financial Modelling',
        'Operations Support',
    ];

    public function run(): void
    {
        $sort = 0;

        foreach (self::SKILLS as $categoryName => $skills) {
            $category = Category::query()->updateOrCreate(
                ['slug' => Str::slug($categoryName)],
                ['name' => $categoryName, 'type' => CategoryType::Skill, 'sort' => ++$sort],
            );

            foreach ($skills as $skillName) {
                Skill::query()->updateOrCreate(
                    ['slug' => Str::slug($skillName)],
                    ['name' => $skillName, 'category_id' => $category->id, 'is_active' => true],
                );
            }
        }

        foreach (self::INDUSTRIES as $i => $industry) {
            Category::query()->updateOrCreate(
                ['slug' => 'industry-'.Str::slug($industry)],
                ['name' => $industry, 'type' => CategoryType::Industry, 'sort' => $i + 1],
            );
        }

        foreach (self::PROJECT_TYPES as $name) {
            ProjectType::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true],
            );
        }
    }
}
