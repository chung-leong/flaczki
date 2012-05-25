package lex.book 
{
	/**
	 * ...
	 * @author Lexcuk
	 */
	public class PageNavigator
	{
		public var max:int;
		
		public var bottom:int;
		public var double:int;
		public var drop:int;
		
		public var leftTr:Boolean;
		
		public var cur:int;
		public var aim:int;
		
		public function action(current:int, target:int):void {
			drop = target;
			bottom = target;
			double = current;
			
			if (current > target) leftTr = true; else leftTr = false;
		}

		public function testAction(aim:int):Boolean {
			this.aim = aim;//цель
			if (aim < 1) aim = 1;
			if (aim > max) aim = max;
			if (aim == cur) return false;
			return true;
		}

	}

}