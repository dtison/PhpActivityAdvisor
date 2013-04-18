<?php
	
	/*  ActivityAdvisor class
		
		Overview 
		
		This class implements an advisor algorithm which is based on user's responses to 
		selected yes/no questions. The user is presented a series of questions for which a 
		cumulative probability vector is computed. These coeffients represent the relative
		probabilities the user will like to do corresponding activities. 
		The probabilities p used to calculate these are stored in a matrix, 
		and are interpreted as p for a yes answer, (1-p`) for no.
				
		Interface:
		
			array String getQuestions() 
			void makeReplies (Array Boolean)
			array string getActivites(int)
			bool debugModeEngage(bool)
			void startOver(bool)
			string postMortemReport()
			
		Implementor notes:  
		
			Error handling and parameter checking are not yet implemented.
			Developed with PHP 5.4.x - earlier versions may not work.
			
		Example usage:
			
			$advisor = new ActivityAdvisor();
			$advisor -> debugModeEngage (true);
			$questions = $advisor->getQuestions();
		
			#  Make a sequence of yes/no's
			$replies[] = true;	#  Weather nice?
			$replies[] = false;	#  Feeling social?
			$replies[] = false;	#  Money to blow?
			$replies[] = true;	#  Feel active?
			$replies[] = false;	#  Hungry?
			$replies[] = true;	#  Want to meet people?
			$replies[] = false;	#  Are you tired?
			$replies[] = false;	#  Like turtles?
		
			$advisor->makeReplies($replies);
			$activities = $advisor->getActivities(3);
		
			print $advisor->postMortemReport();			
		
		*/
	
	require_once ('mysql_iterator.php');
	
	class ActivityAdvisor {
	
		private $number_rows;			#  Rows in matrix
		private $number_cols;			#  Columns in matrix
		private $matrix;				#  Matrix implemented as array
		private $questions;				#  Array of strings of questions to be asked
		private $question_ids;			#  DB id's of questions
		private $activities;			#  Array of strings of activities to be done
		private $activity_ids;			#  DB id's of activities
		private $probabilities_vector;	#  Cumulative probabilites vector
		private $debug_enabled = false;
		private $newline;				#  Either a \n for text or <br> for HTML
		private $debug_string;			#  Text details of processing 
		private $number_questions = 7;
		private $db;					#   MySQLdb mysql iterator

		#  Public interface functions
		#  Constructor		
		public function __construct($sql_host, $sql_username, 
			$sql_password, $sql_database) {
			$this->db = new MySQLdb ($sql_host, $sql_username, $sql_password, $sql_database);	
			$this->newline =  (php_sapi_name() == "cli") ? "\n" : "<br>";
		}

		#  Enables / disables debugging
		public function debugModeEngage ($enable_flag) {
			$this->debug_enabled = $enable_flag;		 
			return $enable_flag;		
		}

		#  Equivalent to destruct then re-instantiate object
		public function startOver() {
	#		$this->initialise();
		}

		#  Returns array of strings of questions
		public function getQuestions() {
		
			#  Make array of pk's from tbl_question
			$sql_result = $this->db->query ("select id from tbl_question");
			for ($sql_result->first(); !$sql_result->end(); $sql_result->next()) {
				$question_ids[] = $sql_result->id;
			}			
			shuffle ($question_ids);
			$question_ids = array_splice ($question_ids, 0, $this->number_questions);
			foreach ($question_ids as $question_id) {
				$sql_result = $this->db->query ("select question from tbl_question where id = $question_id");
				$question = $sql_result->question;
				$return_array[] = array(
					"id" => $question_id,
					"content" => $question
					);
			}

#  TODO rename or simply return json_encode ($return_array);
			$json = json_encode ($return_array);
			return $json;
		}
		
		/*  makeReplies()
			$replies is an array of booleans representing the yes/no responses 
			from user to the number of questions n returned previously by getQuestions().
			The probabilities vector for activities is updated by n iterations as
			an element-wise multiplication with nth row probabilities matrix   */ 

		private function makeReplies ($replies, $question_ids) {
	
			#  Build activities array for number columns, debugging and later use in getActivities()
			$sql_result = $this->db->query ("select id, activity from tbl_activity");
            for ($sql_result->first(); !$sql_result->end(); $sql_result->next()) {
				$this->activity_ids[] 	= $sql_result->id;
				$this->activities[] 	= $sql_result->activity;
			}					
			$this->number_cols = count ($this->activities);

			#  Row count from the question_ids 			
			$this->question_ids = $question_ids;	
			$this->number_rows = count ($this->question_ids);
			
			#  Store the question text's 
			foreach ($this->question_ids as $question_id) {
				$sql_result = $this->db->query ("select question from tbl_question where id = $question_id");
					$this->questions[] = $sql_result->question;
			}

			#  Build probabilities matrix for question_set
			$matrix_row = 0;
			foreach ($this->question_ids as $question_id) {

				#  Build the matrix row
				$sql_result2 = $this->db->query ("select probability from tbl_probability where question_id = $question_id");
    	        for ($sql_result2->first(); !$sql_result2->end(); $sql_result2->next()) {
					$matrix_index = ($matrix_row * $this->number_cols) + $sql_result2->position();					
					$this->matrix[$matrix_index] = $sql_result2->probability;
				}
				$matrix_row++;
			}


			#  Initialise probabilites vector
			#  QtOctive ref: prob = ones(1,length(a));		
			for ($i = 0; $i < $this->number_cols; $i++) {
				$this->probabilities_vector[$i] = 1.0;
			}
			
			#  Want correlation matrix in debug report
			$this->debugReportMRDisplayMatrix();

			#  QtOctive ref: prob = prob.*corr(qx,:);		
			for ($i = 0; $i < $this->number_rows; $i++) {
				$row_index = $this->number_cols * $i;
				$response = $replies [$i];
				$this->debugReportMRQuestion ($i, $response); 

				#  Iterate across row element-wise for vector
				for ($j = 0; $j < $this->number_cols; $j++) {
					$source_index = $row_index + $j;
					$probability = $this->matrix [$source_index];
					if ($replies[$i] == 0) {
						$probability = 1.0 - $probability;
					}
					$this->debugReportMRVectorElement($response, $probability, $j); 
					$this->probabilities_vector[$j] *= $probability;						
				}
				$this->debugReportMRSeparator();											
			}
			$this->debugReportMRVectorCumulative();
		}
		
		public function getActivities ($number_activities, $hj_json) {

			
			#  First get replies & Question ID's from $hj_json
			$replies_array = json_decode ($hj_json, true);
			foreach ($replies_array as $reply) {
				$replies[] = $reply ['answer'] == 'yes' ? 1 : 0;
				$question_ids[] = $reply ['id'];			
			}
				
			#  Now we can call makeReplies()
			$this->makeReplies($replies, $question_ids);
		
			#  Create a temporary array that can be sorted without losing positions
			$cumulative_probabilities = $this->probabilities_vector;
			
			#  Sort by value
			arsort ($cumulative_probabilities);		
			for ($i = 0; $i < $number_activities; $i++) {
				$current_index = key ($cumulative_probabilities);
				$return_array[] = array(
					"id" => $this->activity_ids [$current_index],
					"teaser" => $this->activities [$current_index],
					"descr" => $this->activities [$current_index],
					);
				next ($cumulative_probabilities);
			}

			reset ($cumulative_probabilities);
			$this->debugReportGASortedProbabilities($cumulative_probabilities,$number_activities);
#  TODO rename or simply return json_encode ($return_array);
			$json = json_encode ($return_array);
			return $json;
		}
		
		#  Debugging and diagnostics information
		public function postMortemReport() {
			$question_ids_str = "Question ids: " . print_r ($this->question_ids, true);
			$this->debug_string = 
				"---Begin debug report ---" . $this->newline . 
				"Width [$this->number_cols] height [$this->number_rows] " . $this->newline . 
				"$question_ids_str " . $this->newline .
				$this->debug_string .
				"---End debug report ---" . $this->newline ;
			return $this->debug_string;
		}

		#  All the debug report functions do nothing unless debugging is enabled
		private function debugReportMRQuestion ($index, $response) {
			if ($this->debug_enabled) {
				$question = $this->questions[$index];
				$question_id = $this->question_ids[$index];
				$response_string = $response == 1 ? 'yes' : 'no';
				$this->debug_string .= 
					"Q: [$question] [$question_id] response: [$response_string] $this->newline";
			}	
		}
		
		#  Debugging and diagnostics reporting functions
		private function debugReportMRVectorElement ($response, $probability, $index) {
			if ($this->debug_enabled) {
				$element = $this->probabilities_vector[$index];
				$element_string = sprintf ("%.8f", $element);
				$new_value = $element * $probability;
				$new_value_string = sprintf ("%.8f", $new_value);			
				$activity = $this->activities[$index];
				$this->debug_string .= 
 					"[$element_string] X [$probability] = [$new_value_string]  [$activity]\n";			
			}			
		}

		private function debugReportMRVectorCumulative() {
			if ($this->debug_enabled) {
				$this->debug_string .= "Final cumululative probabilities: $this->newline ";
				foreach ($this->probabilities_vector as $element) {
					$element_string = sprintf ("%.8f", $element);
					$this->debug_string .= "[$element_string] ";	
				}
				$this->debug_string .= "$this->newline";
			}
		}

		private function debugReportMRSeparator() {
			if ($this->debug_enabled) {
				$this->debug_string .= "--- $this->newline";  
			}
		}
		private function debugReportMRDisplayMatrix() {

			if ($this->debug_enabled) {
				$this->debug_string .= "Using correlation matrix:\n";
				for ($i = 0; $i < $this->number_rows; $i++) {
					$row_index = $this->number_cols * $i;
					for ($j = 0; $j < $this->number_cols; $j++) {
						$index = $row_index + $j;
						$value = $this->matrix[$index];
						$this->debug_string .= "$value";
						if ($j < ($this->number_cols - 1)) { 
							$this->debug_string .= ', ';
						}
					}
					$this->debug_string .= ";$this->newline";					
				}
				$this->debug_string .= "$this->newline";
			}
		}
		
		private function debugReportGASortedProbabilities($sorted_probabilities, $number_activities) {
			if ($this->debug_enabled) {
				$this->debug_string .= "Sorted cumulative probabilites and original activity indices:\n";
				$this->debug_string .= print_r ($sorted_probabilities, true);

		
				#  Display the top $number_activites activities
				$this->debug_string .= "Top $number_activities activities computed: $this->newline";
				for ($i = 0; $i < $number_activities; $i++) {
					$activity = $this->activities [key ($sorted_probabilities)];
					$this->debug_string .= "$activity $this->newline";					
					next ($sorted_probabilities);
				}
			}
		}
	}
	
?>