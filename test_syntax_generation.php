<?php

// Quick test to verify the generateIncorrectSentences function works

 

header("Content-Type: application/json");

 

function generateIncorrectSentences($words, $correctSentence) {

    $incorrectOptions = [];

    $attempts = 0;

    $maxAttempts = 50;

 

    while (count($incorrectOptions) < 3 && $attempts < $maxAttempts) {

        $attempts++;

        $shuffledWords = $words;

        shuffle($shuffledWords);

        $shuffledSentence = implode(' ', $shuffledWords);

 

        if ($shuffledSentence !== $correctSentence &&

            !in_array($shuffledSentence, $incorrectOptions)) {

            $incorrectOptions[] = $shuffledSentence;

        }

    }

 

    while (count($incorrectOptions) < 3) {

        $pattern = $words;

 

        if (count($incorrectOptions) === 0) {

            $pattern = array_reverse($words);

        } elseif (count($incorrectOptions) === 1) {

            $first = array_shift($pattern);

            $pattern[] = $first;

        } else {

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

 

// Test with item 1

$words = ['homework', 'diligently', 'finished', 'her', 'Maria'];

$correctSentence = 'Maria diligently finished her homework.';

 

$incorrectOptions = generateIncorrectSentences($words, $correctSentence);

$allOptions = array_merge([$correctSentence], $incorrectOptions);

shuffle($allOptions);

 

echo json_encode([

    'test' => 'Syntax options generation',

    'scrambled_words' => $words,

    'correct_answer' => $correctSentence,

    'generated_incorrect_options' => $incorrectOptions,

    'all_options_shuffled' => $allOptions,

    'optionA' => $allOptions[0] ?? '',

    'optionB' => $allOptions[1] ?? '',

    'optionC' => $allOptions[2] ?? '',

    'optionD' => $allOptions[3] ?? '',

], JSON_PRETTY_PRINT);