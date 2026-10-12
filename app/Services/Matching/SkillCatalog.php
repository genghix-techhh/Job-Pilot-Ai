<?php

namespace App\Services\Matching;

use Illuminate\Support\Str;

/**
 * Local, deterministic skill vocabulary. Used to extract skills from CVs and job
 * descriptions without calling any AI, and to normalise AI-returned skill names.
 */
class SkillCatalog
{
    /**
     * canonical name => [category, ...aliases (lower-case)]
     *
     * @var array<string, array{0: string, 1?: list<string>}>
     */
    private const SKILLS = [
        // Languages
        'PHP' => ['language', ['php']],
        'JavaScript' => ['language', ['javascript', 'ecmascript', 'es6']],
        'TypeScript' => ['language', ['typescript']],
        'Python' => ['language', ['python']],
        'Java' => ['language', ['java']],
        'C#' => ['language', ['c#', 'csharp', 'c sharp']],
        'C++' => ['language', ['c++', 'cpp']],
        'Go' => ['language', ['golang', 'go lang']],
        'Rust' => ['language', ['rust']],
        'Ruby' => ['language', ['ruby']],
        'Kotlin' => ['language', ['kotlin']],
        'Swift' => ['language', ['swiftui', 'swift language', 'swift developer', 'swift programming']],
        'Scala' => ['language', ['scala']],
        'Dart' => ['language', ['dart']],
        'Elixir' => ['language', ['elixir']],
        'SQL' => ['language', ['sql']],
        'Bash' => ['language', ['bash', 'shell scripting', 'shell script']],
        // Front end
        'React' => ['frontend', ['react', 'react.js', 'reactjs']],
        'Next.js' => ['frontend', ['next.js', 'nextjs']],
        'Vue.js' => ['frontend', ['vue', 'vue.js', 'vuejs', 'vue 3']],
        'Nuxt' => ['frontend', ['nuxt', 'nuxt.js']],
        'Angular' => ['frontend', ['angular', 'angularjs']],
        'Svelte' => ['frontend', ['svelte', 'sveltekit']],
        'HTML' => ['frontend', ['html', 'html5']],
        'CSS' => ['frontend', ['css', 'css3']],
        'Sass' => ['frontend', ['sass', 'scss']],
        'Tailwind CSS' => ['frontend', ['tailwind', 'tailwindcss', 'tailwind css']],
        'Bootstrap' => ['frontend', ['bootstrap']],
        'Redux' => ['frontend', ['redux']],
        'jQuery' => ['frontend', ['jquery']],
        'Alpine.js' => ['frontend', ['alpine.js', 'alpinejs']],
        'Webpack' => ['frontend', ['webpack', 'vite']],
        'Responsive Design' => ['frontend', ['responsive design', 'responsive web']],
        'Accessibility' => ['frontend', ['accessibility', 'wcag', 'a11y']],
        // Back end
        'Laravel' => ['backend', ['laravel']],
        'Symfony' => ['backend', ['symfony']],
        'Node.js' => ['backend', ['node.js', 'nodejs', 'node js']],
        'Express' => ['backend', ['express.js', 'expressjs']],
        'NestJS' => ['backend', ['nestjs', 'nest.js']],
        'Django' => ['backend', ['django']],
        'Flask' => ['backend', ['flask']],
        'FastAPI' => ['backend', ['fastapi']],
        'Spring Boot' => ['backend', ['spring boot', 'springboot', 'spring framework']],
        '.NET' => ['backend', ['.net', 'asp.net', 'dotnet', '.net core']],
        'Ruby on Rails' => ['backend', ['ruby on rails', 'rails']],
        'GraphQL' => ['backend', ['graphql']],
        'REST APIs' => ['backend', ['rest api', 'rest apis', 'restful', 'rest-api', 'restful api']],
        'gRPC' => ['backend', ['grpc']],
        'Microservices' => ['backend', ['microservices', 'microservice']],
        'WebSockets' => ['backend', ['websocket', 'websockets']],
        'WordPress' => ['backend', ['wordpress']],
        'Shopify' => ['backend', ['shopify']],
        'Livewire' => ['backend', ['livewire']],
        'Inertia.js' => ['backend', ['inertia', 'inertiajs']],
        'OAuth' => ['backend', ['oauth', 'oauth2', 'openid connect']],
        // Data stores
        'MySQL' => ['data', ['mysql', 'mariadb']],
        'PostgreSQL' => ['data', ['postgresql', 'postgres']],
        'MongoDB' => ['data', ['mongodb', 'mongo db']],
        'Redis' => ['data', ['redis']],
        'SQLite' => ['data', ['sqlite']],
        'Elasticsearch' => ['data', ['elasticsearch', 'opensearch']],
        'Firebase' => ['data', ['firebase', 'firestore']],
        'Supabase' => ['data', ['supabase']],
        'DynamoDB' => ['data', ['dynamodb']],
        'Kafka' => ['data', ['kafka']],
        'RabbitMQ' => ['data', ['rabbitmq']],
        // AI / ML
        'Machine Learning' => ['ai', ['machine learning', 'ml models', 'ml engineer']],
        'Deep Learning' => ['ai', ['deep learning', 'neural network', 'neural networks']],
        'NLP' => ['ai', ['nlp', 'natural language processing']],
        'LLMs' => ['ai', ['llm', 'llms', 'large language model', 'large language models', 'generative ai', 'genai']],
        'Prompt Engineering' => ['ai', ['prompt engineering', 'prompt design']],
        'RAG' => ['ai', ['rag', 'retrieval augmented generation', 'retrieval-augmented generation']],
        'LangChain' => ['ai', ['langchain', 'llamaindex', 'llama index']],
        'OpenAI API' => ['ai', ['openai', 'gpt-4', 'chatgpt api']],
        'Computer Vision' => ['ai', ['computer vision', 'opencv', 'image recognition']],
        'PyTorch' => ['ai', ['pytorch']],
        'TensorFlow' => ['ai', ['tensorflow', 'keras']],
        'scikit-learn' => ['ai', ['scikit-learn', 'sklearn', 'scikit learn']],
        'Pandas' => ['ai', ['pandas']],
        'NumPy' => ['ai', ['numpy']],
        'Hugging Face' => ['ai', ['hugging face', 'huggingface', 'transformers library']],
        'Vector Databases' => ['ai', ['vector database', 'vector databases', 'pinecone', 'pgvector', 'weaviate', 'qdrant', 'chroma']],
        'Data Science' => ['ai', ['data science', 'data scientist']],
        'MLOps' => ['ai', ['mlops', 'ml ops']],
        'Data Engineering' => ['ai', ['data engineering', 'etl', 'airflow', 'dbt']],
        // DevOps / cloud
        'Docker' => ['devops', ['docker', 'docker compose', 'containerization']],
        'Kubernetes' => ['devops', ['kubernetes', 'k8s']],
        'AWS' => ['cloud', ['aws', 'amazon web services', 'ec2', 'aws lambda']],
        'Google Cloud' => ['cloud', ['gcp', 'google cloud']],
        'Azure' => ['cloud', ['azure']],
        'CI/CD' => ['devops', ['ci/cd', 'cicd', 'continuous integration', 'continuous delivery', 'github actions', 'gitlab ci', 'jenkins']],
        'Terraform' => ['devops', ['terraform', 'infrastructure as code']],
        'Linux' => ['devops', ['linux', 'ubuntu server']],
        'Nginx' => ['devops', ['nginx', 'apache server']],
        'Git' => ['tools', ['git', 'github', 'gitlab', 'bitbucket']],
        'Serverless' => ['cloud', ['serverless']],
        // Testing / practices
        'PHPUnit' => ['testing', ['phpunit', 'pest php']],
        'Jest' => ['testing', ['jest', 'vitest']],
        'Cypress' => ['testing', ['cypress', 'playwright', 'selenium']],
        'Pytest' => ['testing', ['pytest']],
        'Automated Testing' => ['testing', ['unit testing', 'integration testing', 'test automation', 'tdd', 'test-driven']],
        'Agile' => ['practice', ['agile', 'scrum', 'kanban']],
        'System Design' => ['practice', ['system design', 'software architecture', 'distributed systems']],
        'Code Review' => ['practice', ['code review', 'code reviews']],
        'Web Scraping' => ['practice', ['web scraping', 'scraping', 'puppeteer']],
        // Mobile
        'React Native' => ['mobile', ['react native']],
        'Flutter' => ['mobile', ['flutter']],
        'Android' => ['mobile', ['android']],
        'iOS' => ['mobile', ['ios development', 'ios app', 'ios']],
        // Design / product tools
        'Figma' => ['tools', ['figma']],
        'Jira' => ['tools', ['jira']],
        'Zapier' => ['tools', ['zapier', 'n8n', 'make.com']],
    ];

    /** Role family => keywords found in titles/descriptions. */
    public const ROLE_KEYWORDS = [
        'Full-stack Developer' => ['full stack', 'full-stack', 'fullstack'],
        'AI/ML Developer' => ['machine learning', 'ml engineer', 'ai engineer', 'ai developer', 'llm', 'artificial intelligence', 'data scientist', 'nlp', 'deep learning', 'applied scientist', 'ai/ml', 'ml/ai'],
        'Software Engineer' => ['software engineer', 'software developer', 'swe', 'application developer', 'programmer', 'sde'],
        'Frontend Developer' => ['frontend', 'front-end', 'front end', 'ui engineer', 'ui developer', 'react developer', 'vue developer', 'web developer'],
        'Backend Developer' => ['backend', 'back-end', 'back end', 'api engineer', 'api developer', 'php developer', 'laravel developer', 'python developer', 'node developer', 'server-side'],
        'Freelance Projects' => ['freelance', 'contract project', 'one-time project', 'fixed price', 'fixed-price', 'gig'],
    ];

    /** @var array<string, string>|null alias regex => canonical */
    private static ?array $patterns = null;

    /** @return list<string> canonical names found in the text, in catalog order */
    public static function extract(string $text): array
    {
        $text = Str::lower($text);
        if (trim($text) === '') {
            return [];
        }
        $found = [];
        foreach (self::patterns() as $regex => $canonical) {
            if (isset($found[$canonical])) {
                continue;
            }
            if (preg_match($regex, $text) === 1) {
                $found[$canonical] = true;
            }
        }

        return array_keys($found);
    }

    /** Map any user/AI supplied skill label to a canonical catalog name when we know it. */
    public static function canonical(string $label): string
    {
        $label = trim($label);
        $key = Str::lower($label);
        foreach (self::SKILLS as $canonical => $def) {
            if (Str::lower($canonical) === $key || in_array($key, $def[1] ?? [], true)) {
                return $canonical;
            }
        }

        return Str::limit($label, 60, '');
    }

    /** Does $text mention this canonical skill (under any of its aliases)? */
    public static function mentions(string $text, string $canonical): bool
    {
        $text = Str::lower($text);
        foreach (self::patterns() as $regex => $name) {
            if ($name === $canonical && preg_match($regex, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function category(string $canonical): ?string
    {
        return self::SKILLS[$canonical][0] ?? null;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::SKILLS);
    }

    /** Which target-role families a piece of text (usually a title) belongs to. @return list<string> */
    public static function rolesIn(string $text): array
    {
        $text = Str::lower($text);
        $roles = [];
        foreach (self::ROLE_KEYWORDS as $role => $words) {
            foreach ($words as $w) {
                if (str_contains($text, $w)) {
                    $roles[] = $role;
                    break;
                }
            }
        }

        return $roles;
    }

    /** @return array<string, string> */
    private static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }
        $out = [];
        foreach (self::SKILLS as $canonical => $def) {
            $aliases = $def[1] ?? [Str::lower($canonical)];
            foreach ($aliases as $alias) {
                $quoted = preg_quote($alias, '/');
                // Boundary that understands symbols in names (C++, C#, .NET, Node.js).
                $out['/(?<![\p{L}\p{N}+#.\-])'.$quoted.'(?![\p{L}\p{N}+#]|\-[\p{L}])/u'] = $canonical;
            }
        }

        return self::$patterns = $out;
    }
}
