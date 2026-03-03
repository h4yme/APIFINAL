<?php

/**

 * Item Formatting Helper Functions

 * Shared between get_preassessment_items.php and get_next_item.php

 */

 

/**

 * Generate incorrect sentence variations by shuffling words

 * @param array $words Array of words to shuffle

 * @param string $correctSentence The correct sentence (to avoid)

 * @return array Array of 3 incorrect sentence variations

 */

function generateIncorrectSentences($words, $correctSentence) {

    $incorrectOptions = [];

    $attempts = 0;

    $maxAttempts = 50; // Prevent infinite loop

 

    while (count($incorrectOptions) < 3 && $attempts < $maxAttempts) {

        $attempts++;

 

        // Shuffle the words

        $shuffledWords = $words;

        shuffle($shuffledWords);

 

        // Create sentence from shuffled words

        $shuffledSentence = implode(' ', $shuffledWords);

 

        // Make sure it's different from correct sentence and not a duplicate

        if ($shuffledSentence !== $correctSentence &&

            !in_array($shuffledSentence, $incorrectOptions)) {

            $incorrectOptions[] = $shuffledSentence;

        }

    }

 

    // If we couldn't generate enough unique variations, create some with basic patterns

    while (count($incorrectOptions) < 3) {

        $pattern = $words;

 

        // Apply different scrambling patterns

        if (count($incorrectOptions) === 0) {

            // Reverse the words

            $pattern = array_reverse($words);

        } elseif (count($incorrectOptions) === 1) {

            // Move first word to end

            $first = array_shift($pattern);

            $pattern[] = $first;

        } else {

            // Move last word to beginning

            $last = array_pop($pattern);

            array_unshift($pattern, $last);

        }

 

        $sentence = implode(' ', $pattern);

        if ($sentence !== $correctSentence && !in_array($sentence, $incorrectOptions)) {

            $incorrectOptions[] = $sentence;

        }

    }

 

    return array_slice($incorrectOptions, 0, 3);

}

 

/**

 * Format a single item for the Android app

 * @param array $item Raw item from database

 * @return array Formatted item

 */

function formatItemForApp($item) {

    $itemType = ucfirst(strtolower(trim($item['ItemType'] ?? '')));

 

    // Parse AnswerChoices JSON if it exists

    $answerChoices = [];

    if (!empty($item['AnswerChoices'])) {

        $decoded = json_decode($item['AnswerChoices'], true);

        $answerChoices = $decoded ?? [];

    }

 

    // Initialize variables

    $optionA = '';

    $optionB = '';

    $optionC = '';

    $optionD = '';

    $correctOption = '';

    $scrambledWords = [];

 

    // Handle different item types

    if ($itemType === 'Syntax') {

        // For Syntax (sentence scramble), split words

        $scrambledWords = array_map('trim', explode(' / ', $item['ItemText']));

 

        // If no answer choices provided, generate scrambled sentence options

        if (empty($answerChoices) && !empty($item['CorrectAnswer'])) {

            $correctSentence = $item['CorrectAnswer'];

            $words = $scrambledWords;

 

            // Generate 3 incorrect variations

            $incorrectOptions = generateIncorrectSentences($words, $correctSentence);

 

            // Combine correct answer with incorrect ones

            $allOptions = array_merge([$correctSentence], $incorrectOptions);

 

            // Shuffle to randomize position

            shuffle($allOptions);

 

            // Assign to options

            $optionA = $allOptions[0] ?? '';

            $optionB = $allOptions[1] ?? '';

            $optionC = $allOptions[2] ?? '';

            $optionD = $allOptions[3] ?? '';

 

            // Find which option is correct

            if ($correctSentence === $optionA) $correctOption = 'A';

            elseif ($correctSentence === $optionB) $correctOption = 'B';

            elseif ($correctSentence === $optionC) $correctOption = 'C';

            elseif ($correctSentence === $optionD) $correctOption = 'D';

        } else {

            // Use provided answer choices if available

            $optionA = $answerChoices[0] ?? '';

            $optionB = $answerChoices[1] ?? '';

            $optionC = $answerChoices[2] ?? '';

            $optionD = $answerChoices[3] ?? '';

 

            $correctAnswer = $item['CorrectAnswer'] ?? '';

            if ($correctAnswer === $optionA) $correctOption = 'A';

            elseif ($correctAnswer === $optionB) $correctOption = 'B';

            elseif ($correctAnswer === $optionC) $correctOption = 'C';

            elseif ($correctAnswer === $optionD) $correctOption = 'D';

        }

    } else {

        // For Spelling, Grammar, Pronunciation - use answer choices

        $optionA = $answerChoices[0] ?? '';

        $optionB = $answerChoices[1] ?? '';

        $optionC = $answerChoices[2] ?? '';

        $optionD = $answerChoices[3] ?? '';

 

        // Determine correct option letter based on CorrectAnswer

        if (!empty($item['CorrectAnswer'])) {

            $correctAnswer = trim($item['CorrectAnswer']);

            if ($correctAnswer === $optionA) $correctOption = 'A';

            elseif ($correctAnswer === $optionB) $correctOption = 'B';

            elseif ($correctAnswer === $optionC) $correctOption = 'C';

            elseif ($correctAnswer === $optionD) $correctOption = 'D';

        }

    }

 

    // Use Phonetic field for pronunciation items (displayed as PassageText)

    $passageText = '';

    if ($itemType === 'Pronunciation' && !empty($item['Phonetic'])) {

        $passageText = $item['Phonetic'];

    }

 

  $isMCQ = !empty($answerChoices) || !empty($optionA);

    $pronunciationSubtype = ($itemType === 'Pronunciation')

        ? ($isMCQ ? 'MCQ' : 'Speak')

        : null;

 

    return [

        'ItemID' => (int)$item['ItemID'],

        'ItemText' => $item['ItemText'] ?? '',

        'QuestionText' => $itemType === 'Syntax'

            ? 'Arrange the words to form a correct sentence:'

            : ($item['ItemText'] ?? ''),

        'PassageText' => $passageText, // Phonetic guide for pronunciation

        'ItemType' => $itemType,

        'PronunciationSubtype' => $pronunciationSubtype, // 'MCQ' or 'Speak' for pronunciation items

        'IsMCQ' => $isMCQ, // Helper flag: true if item has MCQ options

        'DifficultyLevel' => $item['DifficultyLevel'] ?? '',

        'Difficulty' => (float)($item['DifficultyParam'] ?? 0), // Alias

        'DifficultyParam' => (float)($item['DifficultyParam'] ?? 0),

        'Discrimination' => (float)($item['DiscriminationParam'] ?? 1.0), // Alias

        'DiscriminationParam' => (float)($item['DiscriminationParam'] ?? 1.0),

        'GuessingParam' => (float)($item['GuessingParam'] ?? 0.25),

        'AnswerChoices' => $answerChoices,

        'ScrambledWords' => $scrambledWords, // For Syntax type

        'OptionA' => $optionA,

        'OptionB' => $optionB,

        'OptionC' => $optionC,

        'OptionD' => $optionD,

        'CorrectAnswer' => $item['CorrectAnswer'] ?? '',

        'CorrectOption' => $correctOption,

        'ImageURL' => $item['ImageURL'] ?? null,

        'AudioURL' => $item['AudioURL'] ?? null,

        'Phonetic' => $item['Phonetic'] ?? null,

        'Definition' => $item['Definition'] ?? null

    ];

}

?>