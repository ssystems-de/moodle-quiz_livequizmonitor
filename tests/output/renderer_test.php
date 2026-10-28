<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Unit tests for the live quiz monitor renderer.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\output;

use advanced_testcase;
use quiz_livequizmonitor\local\manager\monitor_manager;

/**
 * Tests that the monitor page is rendered through the (theme overridable) plugin renderer.
 *
 * @covers \quiz_livequizmonitor\output\renderer
 */
final class renderer_test extends advanced_testcase {
    /**
     * Create a quiz with one enrolled student and return its monitor report.
     *
     * @return array{0: monitor_report, 1: int} The report and the course module id.
     */
    private function create_report(): array {
        global $PAGE;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $questiongenerator = $generator->get_plugin_generator('core_question');

        $quiz = $quizgenerator->create_instance(['course' => $course->id, 'name' => 'Renderer quiz']);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $cat = $questiongenerator->create_question_category(['contextid' => $context->id]);
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);
        $generator->create_and_enrol($course, 'student');

        $this->setAdminUser();
        $PAGE->set_context($context);
        $PAGE->set_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'livequizmonitor']);

        return [new monitor_report(monitor_manager::get_state($course, $cm, $quiz, 0), 0, ''), (int) $cm->id];
    }

    /**
     * Without a theme override, the plugin renderer renders the monitor page template.
     */
    public function test_default_renderer_renders_monitor_page(): void {
        global $PAGE;
        $this->resetAfterTest();

        [$report, $cmid] = $this->create_report();
        $renderer = $PAGE->get_renderer('quiz_livequizmonitor');
        $html = $renderer->render($report);

        $this->assertSame(renderer::class, get_class($renderer));
        $this->assertStringContainsString('data-region="live-quiz-monitor"', $html);
        $this->assertStringContainsString('data-cmid="' . $cmid . '"', $html);
        $this->assertStringContainsString('Renderer quiz', $html);
    }

    /**
     * A theme renderer overriding render_monitor_report() is used instead of the plugin renderer.
     *
     * The fixture declares a renderer in the theme_boost namespace, so it must not leak into other tests.
     *
     * @runInSeparateProcess
     */
    public function test_theme_override_is_used(): void {
        global $PAGE;
        $this->resetAfterTest();
        require_once(__DIR__ . '/../fixtures/theme_override_renderer.php');

        [$report] = $this->create_report();
        $renderer = $PAGE->get_renderer('quiz_livequizmonitor');

        $this->assertInstanceOf(\theme_boost\output\quiz_livequizmonitor_renderer::class, $renderer);
        $this->assertSame(\theme_boost\output\quiz_livequizmonitor_renderer::MARKER, $renderer->render($report));
    }
}
