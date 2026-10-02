<?php
/**
 * منقولة حرفيًا من config/ai.php القديمة. إعدادات عامة لأي endpoint
 * متوافق مع OpenAI /chat/completions، بيستهلكها App\Services\Ai\AIClient.
 * تغيير AI_PROVIDER/AI_BASE_URL/AI_MODEL في .env كفاية للتبديل لمزوّد
 * تاني من غير أي تعديل كود — نفس فلسفة القديمة بالظبط.
 */

return [
    'enabled'  => (bool) env('AI_FEATURES_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'none'),

    // أي endpoint متوافق مع OpenAI /chat/completions (OpenAI نفسها،
    // Azure OpenAI gateway، OpenRouter، vLLM ذاتي الاستضافة، ...).
    'base_url' => rtrim((string) env('AI_BASE_URL', 'https://api.openai.com/v1'), '/'),
    'api_key'  => env('AI_API_KEY', ''),
    'model'    => env('AI_MODEL', 'gpt-4o-mini'),
    // نفس القديمة: 30 ثانية كحد أدنى آمن (بعض الطلبات — خصوصًا AI
    // Assistant الكامل بسياق طويل — بتاخد أكتر من الافتراضي القديم 12s).
    // زوّد AI_TIMEOUT في .env حسب المزوّد بتاعك لو احتجت.
    'timeout'  => (int) env('AI_TIMEOUT', 30),

    // مفتاح على مستوى الميزة: حتى لو enabled فوق = true، تخصيص الإيميلات
    // فاضل مقفول لحد ما ده يتفعّل صراحة.
    'email_personalization_enabled' => (bool) env('AI_EMAIL_PERSONALIZATION_ENABLED', false),

    // بيانات مؤسس/مطوّر المنصة اللي المساعد بيدّيها لأي يوزر يسأل عن التواصل
    // أو عن اللي بنى المنصة. تتغيّر من .env من غير تعديل كود.
    'founder' => [
        'name_en'  => env('UIP_FOUNDER_NAME_EN', 'Haitham Mohamed'),
        'name_ar'  => env('UIP_FOUNDER_NAME_AR', 'هيثم محمد'),
        'email'    => env('UIP_FOUNDER_EMAIL', 'haythemmohamed478@gmail.com'),
        'phone'    => env('UIP_FOUNDER_PHONE', '01143894042'),
        'linkedin' => env('UIP_FOUNDER_LINKEDIN', 'https://www.linkedin.com/in/haithem-mohamed-8b7143243/'),
    ],
];
